#!/usr/bin/env python3
"""
Dezzme AI — ask.py
====================
Called by PHP via shell_exec():
    python ask.py "user message here" [user_id] [username]

Prints a single line of JSON:
    {"response": "...", "tag": "...", "confidence": 0.92}

PHP reads stdout and json_decodes it.
"""

import sys
import json
import os

# ── FIX WINDOWS UTF-8 EMOJI OUTPUT ──────────────────────
sys.stdout.reconfigure(encoding='utf-8')

# ── Make sure brain.py is importable from same directory ──
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from brain import load_model, predict


def main():
    # ── Read arguments from PHP ──────────────────────────
    if len(sys.argv) < 2:
        print(json.dumps({
            "response":   "Hey! Ask me anything about Dezzme 😊",
            "tag":        "greeting",
            "confidence": 1.0,
        }, ensure_ascii=False))
        return

    user_message = sys.argv[1].strip()
    user_id      = sys.argv[2] if len(sys.argv) > 2 else "0"
    username     = sys.argv[3] if len(sys.argv) > 3 else "Guest"

    # ── Handle empty message ─────────────────────────────
    if not user_message:
        print(json.dumps({
            "response":   "I didn't catch that! Try asking me something about Dezzme 😊",
            "tag":        "fallback",
            "confidence": 0.0,
        }, ensure_ascii=False))
        return

    # ── Personalise greeting if username known ───────────
    personalised_greetings = {
        "greeting": True,
        "help_support": True,
    }

    try:
        model  = load_model()
        result = predict(user_message, model)

        # Inject username into greeting responses for logged-in users
        if result["tag"] in personalised_greetings and username != "Guest" and username != "0":
            if "!" in result["response"]:
                parts = result["response"].split("!", 1)
                result["response"] = parts[0] + f", {username}!" + parts[1]

        output = {
            "response":   result["response"],
            "tag":        result["tag"],
            "confidence": result["confidence"],
        }

    except Exception as e:
        output = {
            "response":   "Oops! My brain had a hiccup 😅 Try again in a moment!",
            "tag":        "error",
            "confidence": 0.0,
            "error":      str(e),
        }

    # ── Output JSON for PHP to read ──────────────────────
    print(json.dumps(output, ensure_ascii=False))


if __name__ == "__main__":
    main()