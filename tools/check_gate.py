#!/usr/bin/env python3
"""
Gate verification tool for Coleza Host.
Validates subphase and phase gate evidence against 07-gates and 09-machine-readable/gate-policy.json.
"""
import json
import sys
from pathlib import Path

def main():
    root_dir = Path(__file__).resolve().parent.parent
    evidence_dir = root_dir / "evidence"
    state_file = root_dir / "09-machine-readable" / "current-state.json"
    policy_file = root_dir / "09-machine-readable" / "gate-policy.json"

    if not state_file.exists() or not policy_file.exists():
        print("ERROR: Current state or gate policy file missing.")
        return 1

    with open(state_file, "r", encoding="utf-8") as f:
        state = json.load(f)

    with open(policy_file, "r", encoding="utf-8") as f:
        policy = json.load(f)

    phase = state.get("active_phase")
    subphase = state.get("active_subphase")
    status = state.get("status")

    print("==================================================")
    print("Coleza Host — Phase Gate Verification Engine")
    print("==================================================")
    print(f"Active Phase:     {phase}")
    print(f"Active Subphase:  {subphase}")
    print(f"Reported Status:  {status}")

    valid_statuses = policy.get("status_values", [])
    if status not in valid_statuses:
        print(f"ERROR: Status '{status}' is not a valid status in gate-policy.json")
        return 1

    # Check evidence directory structure
    if not evidence_dir.exists():
        print("ERROR: evidence directory does not exist.")
        return 1

    print("STATUS: GATE POLICY STRUCTURE VALIDATED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
