#!/usr/bin/env python3
"""
Scope and machine-readable dependency graph validator for Coleza Host.
Ensures V1, V1.1, V2+ scope contracts and domain dependency graph are coherent.
"""
import json
import sys
from pathlib import Path

def main():
    root_dir = Path(__file__).resolve().parent.parent
    mr_dir = root_dir / "09-machine-readable"

    # 1. Run plan validation script
    from importlib.machinery import SourceFileLoader
    validate_plan = SourceFileLoader("validate_plan", str(mr_dir / "validate_plan.py")).load_module()

    # 2. Check scope contracts
    scope_file = mr_dir / "scope.json"
    with open(scope_file, "r", encoding="utf-8") as f:
        scope_data = json.load(f)

    for required_cat in ["V1-MUST", "V1-FOUNDATION", "V1.1", "V2+"]:
        if required_cat not in scope_data.get("scope", {}):
            print(f"ERROR: Missing scope category: {required_cat}")
            return 1

    # 3. Check domain graph for cycles (DFS topological sort check)
    domains_file = mr_dir / "domains.json"
    with open(domains_file, "r", encoding="utf-8") as f:
        domain_data = json.load(f)["domains"]

    visited = {}  # 0: unvisited, 1: visiting, 2: visited
    def has_cycle(node):
        visited[node] = 1
        deps = domain_data.get(node, {}).get("hard", [])
        for dep in deps:
            if dep not in domain_data:
                continue
            if visited.get(dep, 0) == 1:
                return True
            if visited.get(dep, 0) == 0:
                if has_cycle(dep):
                    return True
        visited[node] = 2
        return False

    for d in domain_data:
        if visited.get(d, 0) == 0:
            if has_cycle(d):
                print(f"ERROR: Circular dependency detected involving domain '{d}'")
                return 1

    print("==================================================")
    print("Coleza Host — Scope & Dependency Graph Verification")
    print("==================================================")
    print(f"Validated domains count: {len(domain_data)}")
    print("No circular dependencies detected.")
    print("STATUS: SCOPE AND DEPENDENCY GRAPH VERIFIED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
