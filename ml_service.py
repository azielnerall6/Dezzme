"""
ml_service.py — Python ML microservice (the brain)
PHP calls this on port 5001.

Install:
    pip install fastapi uvicorn numpy sentence-transformers rapidfuzz

Run:
    python ml_service.py
"""

from fastapi import FastAPI
from pydantic import BaseModel
from typing import List, Optional, Dict
import numpy as np
import json, os
from collections import defaultdict
from difflib import get_close_matches

app = FastAPI(title="Dezzme ML Brain")

# ─────────────────────────────────────────────────────────
# OPTIONAL DEPENDENCIES — degrade gracefully if missing
# ─────────────────────────────────────────────────────────

EMBEDDINGS_AVAILABLE = False
_model = None
try:
    from importlib import import_module
    SentenceTransformer = import_module("sentence_transformers").SentenceTransformer
    EMBEDDINGS_AVAILABLE = True
except ImportError:
    pass

FUZZY_AVAILABLE = False
try:
    from rapidfuzz import process, fuzz
    FUZZY_AVAILABLE = True
except ImportError:
    pass


def get_model():
    global _model
    if _model is None and EMBEDDINGS_AVAILABLE:
        _model = SentenceTransformer('all-MiniLM-L6-v2')
    return _model


# ─────────────────────────────────────────────────────────
# PERSISTENT STATE
# ─────────────────────────────────────────────────────────

EMBED_FILE = "tag_embeddings.json"
COOC_FILE  = "tag_vectors.json"        # legacy co-occurrence model, kept as a secondary signal

tag_embeddings   = {}     # {tag: [floats]}  — semantic vectors, work cold (no usage history needed)
tag_cooc_vectors = {}     # {tag: [floats]}  — co-occurrence vectors, learn from real behavior
known_vocabulary  = set() # everything Python has ever seen — backs spellcheck


def load_state():
    global tag_embeddings, tag_cooc_vectors, known_vocabulary
    if os.path.exists(EMBED_FILE):
        with open(EMBED_FILE) as f:
            tag_embeddings = json.load(f)
    if os.path.exists(COOC_FILE):
        with open(COOC_FILE) as f:
            tag_cooc_vectors = json.load(f)
    known_vocabulary = set(tag_embeddings.keys()) | set(tag_cooc_vectors.keys())
    print(f"[ml_service] loaded {len(tag_embeddings)} embeddings, "
          f"{len(tag_cooc_vectors)} co-occurrence vectors, "
          f"embeddings_available={EMBEDDINGS_AVAILABLE}, fuzzy_available={FUZZY_AVAILABLE}")


def save_embeddings():
    with open(EMBED_FILE, "w") as f:
        json.dump(tag_embeddings, f)


def save_cooc():
    with open(COOC_FILE, "w") as f:
        json.dump(tag_cooc_vectors, f)


load_state()


def cosine_similarity(a, b) -> float:
    a, b = np.array(a), np.array(b)
    denom = np.linalg.norm(a) * np.linalg.norm(b)
    if denom == 0:
        return 0.0
    return float(np.dot(a, b) / denom)


# ─────────────────────────────────────────────────────────
# SIMILARITY — embeddings first (work immediately, even with
# zero history), co-occurrence second (learns real user behavior
# once you have data). Blended so the brain uses whatever it knows.
# ─────────────────────────────────────────────────────────

def similar_from_embeddings(tag: str, top_n: int) -> List[dict]:
    if tag not in tag_embeddings or len(tag_embeddings) < 2:
        return []
    vec_a = tag_embeddings[tag]
    scores = []
    for other, vec_b in tag_embeddings.items():
        if other == tag:
            continue
        scores.append((other, cosine_similarity(vec_a, vec_b)))
    scores.sort(key=lambda x: x[1], reverse=True)
    return [{"tag": "#"+t, "similarity": round(s, 4), "source": "embedding"} for t, s in scores[:top_n]]


def similar_from_cooccurrence(tag: str, top_n: int) -> List[dict]:
    if tag not in tag_cooc_vectors or len(tag_cooc_vectors) < 2:
        return []
    vec_a = tag_cooc_vectors[tag]
    scores = []
    for other, vec_b in tag_cooc_vectors.items():
        if other == tag:
            continue
        scores.append((other, cosine_similarity(vec_a, vec_b)))
    scores.sort(key=lambda x: x[1], reverse=True)
    return [{"tag": "#"+t, "similarity": round(s, 4), "source": "cooccurrence"} for t, s in scores[:top_n]]


def get_similar_tags(tag: str, top_n: int = 10) -> List[dict]:
    tag = tag.lower().strip("#")
    merged: Dict[str, dict] = {}

    for entry in similar_from_embeddings(tag, top_n * 2):
        merged[entry["tag"]] = entry

    for entry in similar_from_cooccurrence(tag, top_n * 2):
        key = entry["tag"]
        if key not in merged or entry["similarity"] > merged[key]["similarity"]:
            merged[key] = entry

    ranked = sorted(merged.values(), key=lambda x: x["similarity"], reverse=True)
    return ranked[:top_n]


# ─────────────────────────────────────────────────────────
# REQUEST MODELS
# ─────────────────────────────────────────────────────────

class IndexRequest(BaseModel):
    tags: List[str]

class SimilarTagsRequest(BaseModel):
    tag: str
    top_n: Optional[int] = 10

class ScoreBoostRequest(BaseModel):
    tags: List[str]
    user_tags: List[str]

class SpellcheckRequest(BaseModel):
    query: str
    vocabulary: Optional[List[str]] = None   # if omitted, uses Python's own known vocabulary
    cutoff: Optional[float] = 0.7

class TrainRequest(BaseModel):
    sessions: List[List[str]]

class Candidate(BaseModel):
    tag: str
    source: str = "popular"     # personal | trending | global | related | popular
    pop_score: float = 0.0
    trend_score: float = 0.0
    geo_score: float = 0.0
    base_score: float = 0.0

class RankRequest(BaseModel):
    candidates: List[Candidate]
    user_top_tags: List[str] = []
    limit: int = 8


# ─────────────────────────────────────────────────────────
# ENDPOINTS
# ─────────────────────────────────────────────────────────

@app.get("/health")
def health():
    return {
        "status": "ok",
        "embeddings_available": EMBEDDINGS_AVAILABLE,
        "fuzzy_available": FUZZY_AVAILABLE,
        "embeddings_indexed": len(tag_embeddings),
        "cooccurrence_indexed": len(tag_cooc_vectors),
        "vocabulary_size": len(known_vocabulary),
    }


@app.post("/index")
def index_tags(req: IndexRequest):
    """
    Call whenever your tag vocabulary changes (new captions, new searches,
    nightly cron). Embeds every new tag once so /similar works immediately,
    even with zero usage history — this is what fixes cold start.
    """
    global known_vocabulary
    clean = sorted({t.lower().strip("#") for t in req.tags if t and t.strip()})
    known_vocabulary |= set(clean)

    if not EMBEDDINGS_AVAILABLE:
        return {"ok": False, "reason": "sentence-transformers not installed", "indexed": 0}

    model = get_model()
    to_embed = [t for t in clean if t not in tag_embeddings]
    if to_embed:
        vecs = model.encode(to_embed, show_progress_bar=False)
        for t, v in zip(to_embed, vecs):
            tag_embeddings[t] = v.tolist()
        save_embeddings()

    return {"ok": True, "newly_indexed": len(to_embed), "total_indexed": len(tag_embeddings)}


@app.post("/similar")
def similar_tags(req: SimilarTagsRequest):
    """Replaces the old hardcoded $semanticMap entirely with learned similarity."""
    return {"tag": req.tag, "similar": get_similar_tags(req.tag, req.top_n)}


@app.post("/boost")
def score_boost(req: ScoreBoostRequest):
    """Kept for backward compatibility — now backed by blended similarity, not raw co-occurrence."""
    boosts = {}
    for tag in req.tags:
        clean = tag.lower().strip("#")
        boost = 0.0
        for user_tag in req.user_tags:
            for s in get_similar_tags(user_tag, 25):
                if s["tag"].strip("#") == clean:
                    boost = max(boost, s["similarity"] * 0.3)
                    break
        boosts[tag] = round(boost, 4)
    return {"boosts": boosts}


@app.post("/spellcheck")
def spellcheck(req: SpellcheckRequest):
    """
    Typo detection. Uses rapidfuzz if installed (handles transpositions/partials
    better), falls back to stdlib difflib otherwise — never goes fully dark
    just because a pip package is missing.
    """
    q = req.query.lower().strip()
    vocab = [v.lower().strip("#") for v in req.vocabulary] if req.vocabulary else list(known_vocabulary)
    if not vocab or not q:
        return {"corrected": None, "suggestions": []}

    if FUZZY_AVAILABLE:
        matches = process.extract(q, vocab, scorer=fuzz.WRatio, limit=5)
        good = [m for m in matches if m[1] >= req.cutoff * 100]
        suggestions = [{"tag": "#"+m[0], "score": round(m[1]/100, 4)} for m in good]
        corrected = suggestions[0]["tag"] if suggestions else None
    else:
        matches = get_close_matches(q, vocab, n=5, cutoff=req.cutoff)
        suggestions = [{"tag": "#"+m, "score": None} for m in matches]
        corrected = ("#"+matches[0]) if matches else None

    return {"corrected": corrected, "suggestions": suggestions}


@app.post("/rank")
def rank_candidates(req: RankRequest):
    """
    The big one — this is where the brain replaces PHP's hand-tuned formula.
    PHP hands over raw signals per candidate; Python computes personalization
    via real similarity (not a hardcoded map), blends it with popularity/trend/
    geo, and returns a ready-to-display ranked list.

    If this endpoint is down, PHP's own weighted-sum fallback still runs fine —
    this just makes the result smarter when it's up.
    """
    scored = []
    for c in req.candidates:
        clean = c.tag.lower().strip("#")

        pers_score = 0.0
        for user_tag in req.user_top_tags:
            for s in get_similar_tags(user_tag, 25):
                if s["tag"].strip("#") == clean:
                    pers_score = max(pers_score, s["similarity"])
                    break
        if clean in [t.lower() for t in req.user_top_tags]:
            pers_score = 1.0

        source_weight = {
            "personal": 1.0, "trending": 0.7, "global": 0.5,
            "related": 0.4, "popular": 0.3,
        }.get(c.source, 0.2)

        final_score = (
            0.25 * c.pop_score +
            0.20 * c.trend_score +
            0.25 * pers_score +
            0.10 * c.geo_score +
            0.10 * source_weight +
            0.10 * c.base_score
        )

        scored.append({"tag": c.tag, "source": c.source, "score": round(final_score, 4)})

    scored.sort(key=lambda x: x["score"], reverse=True)

    # guarantee at least one item per source survives, same idea your PHP had
    seen, mixed = set(), []
    by_source = defaultdict(list)
    for s in scored:
        by_source[s["source"]].append(s)
    for src in ["personal", "trending", "global", "related", "popular"]:
        if by_source[src]:
            top = by_source[src][0]
            if top["tag"] not in seen:
                seen.add(top["tag"])
                mixed.append(top)
    for s in scored:
        if len(mixed) >= req.limit:
            break
        if s["tag"] not in seen:
            seen.add(s["tag"])
            mixed.append(s)

    mixed.sort(key=lambda x: x["score"], reverse=True)
    return {"ranked": mixed[:req.limit]}


@app.post("/train")
def train_model(req: TrainRequest):
    """
    Builds the co-occurrence signal — secondary to embeddings, but valuable
    because it captures your users' actual behavior, not just general meaning.
    Call nightly from a cron pulling sessions out of search_user_signals.
    """
    global tag_cooc_vectors, known_vocabulary

    if not req.sessions:
        return {"error": "no sessions provided"}

    co = defaultdict(lambda: defaultdict(float))
    tag_counts = defaultdict(float)

    for session in req.sessions:
        session = [t.lower().strip("#") for t in session if t.strip()]
        for i, tag_a in enumerate(session):
            tag_counts[tag_a] += 1
            for j, tag_b in enumerate(session):
                if i != j:
                    co[tag_a][tag_b] += 1.0 / (abs(i - j) + 1)

    all_tags = list(tag_counts.keys())
    if len(all_tags) < 2:
        return {"error": "not enough unique tags to train"}

    vectors = {}
    for tag in all_tags:
        vec = np.array([co[tag].get(other, 0.0) for other in all_tags])
        norm = np.linalg.norm(vec)
        vectors[tag] = (vec / norm).tolist() if norm > 0 else vec.tolist()

    tag_cooc_vectors = vectors
    known_vocabulary |= set(all_tags)
    save_cooc()

    return {"ok": True, "tags_trained": len(all_tags), "sessions_used": len(req.sessions)}


@app.post("/train_from_db")
def train_from_db_hint():
    return {
        "hint": "Pull recent sessions from search_user_signals grouped by user_id+date, "
                "POST the tag lists to /train. Also POST your full tag vocabulary to /index "
                "on the same cron so embeddings stay current."
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=5001)