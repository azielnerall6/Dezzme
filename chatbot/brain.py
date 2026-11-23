#!/usr/bin/env python3
"""
Dezzme AI Brain
================
TF-IDF + Cosine Similarity intent classifier.
Trained on knowledge.json — run this file directly to train:
    python brain.py

It saves model.pkl which ask.py loads at runtime.
"""

import json
import pickle
import random
import re
import os
import sys
import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

# ── FIX WINDOWS UTF-8 EMOJI OUTPUT ──────────────────────
sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

# ── Paths ────────────────────────────────────────────────
BASE_DIR     = os.path.dirname(os.path.abspath(__file__))
KNOWLEDGE    = os.path.join(BASE_DIR, "knowledge.json")
MODEL_FILE   = os.path.join(BASE_DIR, "model.pkl")

# ── Confidence threshold ─────────────────────────────────
MIN_CONFIDENCE = 0.15   # below this → fallback response


# ═══════════════════════════════════════════════════════
#  TEXT PREPROCESSING
# ═══════════════════════════════════════════════════════
def clean_text(text: str) -> str:
    """Lowercase, strip punctuation, normalise whitespace."""
    text = text.lower().strip()
    text = re.sub(r"[^\w\s]", " ", text)
    text = re.sub(r"\s+", " ", text)
    return text


# ═══════════════════════════════════════════════════════
#  TRAINING
# ═══════════════════════════════════════════════════════
def train():
    """
    Load knowledge.json, build TF-IDF vectoriser over all patterns,
    and save (vectoriser + tag_list + response_map) to model.pkl.
    """
    print("🧠 Dezzme AI — Training started...")

    with open(KNOWLEDGE, "r", encoding="utf-8") as f:
        data = json.load(f)

    intents = data["intents"]

    corpus   = []
    tags     = []
    response_map = {}

    for intent in intents:
        tag = intent["tag"]
        response_map[tag] = intent["responses"]

        for pattern in intent["patterns"]:
            corpus.append(clean_text(pattern))
            tags.append(tag)

    if not corpus:
        print("❌ No patterns found in knowledge.json!")
        sys.exit(1)

    # Build TF-IDF vectoriser
    vectoriser = TfidfVectorizer(
        ngram_range=(1, 2),
        min_df=1,
        max_features=8000,
        sublinear_tf=True,
    )

    X = vectoriser.fit_transform(corpus)

    model_data = {
        "vectoriser":    vectoriser,
        "X":             X,
        "tags":          tags,
        "response_map":  response_map,
        "fallback_tag":  "fallback",
    }

    with open(MODEL_FILE, "wb") as f:
        pickle.dump(model_data, f)

    unique_tags = len(set(tags))

    print("✅ Training complete!")
    print(f"   • Intents  : {unique_tags}")
    print(f"   • Patterns : {len(corpus)}")
    print(f"   • Model    : {MODEL_FILE}")


# ═══════════════════════════════════════════════════════
#  PREDICTION
# ═══════════════════════════════════════════════════════
def predict(user_input: str, model_data: dict) -> dict:
    """
    Given cleaned user input, return best-matching intent tag,
    confidence score, and a randomly selected response.
    """

    vectoriser   = model_data["vectoriser"]
    X            = model_data["X"]
    tags         = model_data["tags"]
    response_map = model_data["response_map"]
    fallback_tag = model_data["fallback_tag"]

    cleaned = clean_text(user_input)

    # Edge case: empty input
    if not cleaned.strip():
        return {
            "tag":        fallback_tag,
            "confidence": 0.0,
            "response":   random.choice(
                response_map.get(
                    fallback_tag,
                    ["I didn't understand that!"]
                )
            ),
        }

    try:
        user_vec = vectoriser.transform([cleaned])
        sims     = cosine_similarity(user_vec, X).flatten()

        best_idx   = int(np.argmax(sims))
        confidence = float(sims[best_idx])
        tag        = tags[best_idx]

    except Exception:
        tag = fallback_tag
        confidence = 0.0

    # Confidence gate
    if confidence < MIN_CONFIDENCE:
        tag = fallback_tag

    response = random.choice(
        response_map.get(
            tag,
            response_map.get(
                fallback_tag,
                ["Sorry, I didn't get that!"]
            )
        )
    )

    return {
        "tag":        tag,
        "confidence": round(confidence, 4),
        "response":   response,
    }


# ═══════════════════════════════════════════════════════
#  LOAD MODEL (cached singleton)
# ═══════════════════════════════════════════════════════
_model_cache = None

def load_model() -> dict:
    global _model_cache

    if _model_cache is None:

        if not os.path.exists(MODEL_FILE):
            train()

        with open(MODEL_FILE, "rb") as f:
            _model_cache = pickle.load(f)

    return _model_cache


# ═══════════════════════════════════════════════════════
#  INTERACTIVE TEST
# ═══════════════════════════════════════════════════════
if __name__ == "__main__":

    train()
    model = load_model()

    print("\n🎭 Dezzme AI — Interactive Test Mode")
    print("   Type a question (or 'quit' to exit)\n")

    while True:

        try:
            user_input = input("You: ").strip()

        except (EOFError, KeyboardInterrupt):
            print("\nGoodbye!")
            break

        if user_input.lower() in ("quit", "exit", "bye"):
            print("DezzBot: Later! 👋")
            break

        if not user_input:
            continue

        result = predict(user_input, model)

        print(
            f"DezzBot "
            f"[{result['tag']} | {result['confidence']:.2f}]: "
            f"{result['response']}\n"
        )