# Working agreement

How I worked with an AI assistant (Claude Code) on this repository. The design itself is in the README.

## Goal
A small Laravel API that handles one complete billing request end to end, readable in under an hour, built in a ~4 hour timebox.

## Rules for the assistant
- Build in small steps; after each step, explain what was written and why, so I can review it before moving on.
- Keep it small. No packages or patterns that the main request doesn't need.
- Comments explain *why*, not what the framework does.
- Run the tests after every step and keep CI green. One commit per step.
- Leave honest gaps instead of half-built features, and list them in the README.

## Non-negotiable
No double charges, no string-built SQL, no plaintext passwords, no personal data in logs, no floating-point money.

## How I reviewed the output
Read every file line by line, ran the tests and the app locally, and cut what I couldn't justify. Two bugs were caught by tests along the way (a null `role` after register, and a 500 instead of a 401 without an `Accept` header); both are fixed with a comment at the fix.
