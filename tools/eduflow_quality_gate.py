#!/usr/bin/env python3

from pathlib import Path
import subprocess
import re
import sys
from collections import Counter, defaultdict

ROOT = Path("eduflow-core")

errors = []
warnings = []
info = []

if not ROOT.exists():
    print("FATAL: eduflow-core folder not found")
    sys.exit(2)

php_files = sorted(ROOT.rglob("*.php"))

print("=" * 70)
print("EDUFLOW CORPORATE QUALITY GATE")
print("=" * 70)
print(f"PHP files found: {len(php_files)}")
print()

# ---------------------------------------------------------
# 1. PHP syntax
# ---------------------------------------------------------
print("1) PHP SYNTAX")

for f in php_files:
    r = subprocess.run(
        ["php", "-l", str(f)],
        capture_output=True,
        text=True
    )

    if r.returncode != 0:
        errors.append(
            f"PHP syntax error: {f}\n{r.stdout}{r.stderr}"
        )

print(
    "   PASS" if not any("PHP syntax" in x for x in errors)
    else "   FAIL"
)

# ---------------------------------------------------------
# 2. Version consistency
# ---------------------------------------------------------
print("2) VERSION CONSISTENCY")

main = ROOT / "eduflow-core.php"

if not main.exists():
    errors.append("Main plugin file missing: eduflow-core.php")
else:
    text = main.read_text(errors="ignore")

    header = re.search(
        r"\*\s*Version:\s*([^\s]+)",
        text
    )

    const = re.search(
        r"EDUFLOW_CORE_VERSION'\s*,\s*'([^']+)'",
        text
    )

    if not header or not const:
        errors.append(
            "Plugin version header or EDUFLOW_CORE_VERSION missing"
        )
    elif header.group(1) != const.group(1):
        errors.append(
            f"Version mismatch: header={header.group(1)} "
            f"constant={const.group(1)}"
        )
    else:
        info.append(f"Version: {header.group(1)}")

# ---------------------------------------------------------
# 3. require_once files
# ---------------------------------------------------------
print("3) REQUIRED FILES")

if main.exists():
    text = main.read_text(errors="ignore")

    requires = re.findall(
        r"require_once\s+EDUFLOW_CORE_DIR\s*\.\s*'([^']+)'",
        text
    )

    for rel in requires:
        target = ROOT / rel

        if not target.exists():
            errors.append(
                f"Required file missing: {rel}"
            )

# ---------------------------------------------------------
# 4. Known dangerous typo patterns
# ---------------------------------------------------------
print("4) KNOWN REGRESSION PATTERNS")

bad_patterns = {
    r"get_error_messag\s*\(":
        "Typo get_error_messag()",

    r"count\s*\(\s*error_details\s*\)":
        "Missing $ in count(error_details)",

    r"/path/to/wordpress":
        "Placeholder WordPress path left in source",

    r"PYold":
        "Broken pasted Python/PHP artifact",

    r"\bSUCCESS::":
        "Broken shell/paste artifact",

    r"TODO\s*PRODUCTION":
        "Production TODO still present",

    r"FIXME":
        "FIXME still present",
}

for f in php_files:
    text = f.read_text(errors="ignore")

    for pattern, label in bad_patterns.items():
        if re.search(pattern, text, re.I):
            errors.append(
                f"{label}: {f}"
            )

# ---------------------------------------------------------
# 5. Duplicate methods inside same PHP file
# ---------------------------------------------------------
print("5) DUPLICATE METHODS")

def class_blocks(text):
    import re

    pattern = re.compile(
        r'\b(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)[^{]*\{'
    )

    results = []

    for match in pattern.finditer(text):
        name = match.group(1)
        brace = text.find("{", match.start())

        if brace == -1:
            continue

        depth = 0
        end_pos = None

        for i in range(brace, len(text)):
            if text[i] == "{":
                depth += 1
            elif text[i] == "}":
                depth -= 1

                if depth == 0:
                    end_pos = i + 1
                    break

        if end_pos:
            results.append(
                (
                    name,
                    text[brace:end_pos]
                )
            )

    return results


for f in php_files:
    text = f.read_text(errors="ignore")

    for class_name, body in class_blocks(text):

        methods = re.findall(
            r"(?:public|private|protected)\s+"
            r"(?:static\s+)?function\s+([A-Za-z0-9_]+)\s*\(",
            body
        )

        dupes = [
            name
            for name, count in Counter(methods).items()
            if count > 1
        ]

        if dupes:
            warnings.append(
                f"Duplicate methods in {class_name} ({f}): "
                + ", ".join(dupes)
            )

# ---------------------------------------------------------
# 6. EduFlow classes defined
# ---------------------------------------------------------
print("6) CLASS DEFINITIONS")

class_defs = defaultdict(list)

for f in php_files:
    text = f.read_text(errors="ignore")

    for cls in re.findall(
        r"\bclass\s+(EduFlow_[A-Za-z0-9_]+)",
        text
    ):
        class_defs[cls].append(str(f))

for cls, files in class_defs.items():
    if len(files) > 1:
        warnings.append(
            f"Class defined multiple times: {cls} -> "
            + ", ".join(files)
        )

# ---------------------------------------------------------
# 7. Admin-post actions referenced vs registered
# ---------------------------------------------------------
print("7) ADMIN ACTION WIRING")

referenced_actions = set()
registered_actions = set()

for f in php_files:
    text = f.read_text(errors="ignore")

    # -----------------------------------------------------
    # Actions referenced by forms/URLs.
    # -----------------------------------------------------
    referenced_actions.update(
        re.findall(
            r"(?:action=|name=[\"']action[\"']\s+value=[\"'])"
            r"(eduflow_[A-Za-z0-9_]+)",
            text
        )
    )

    # -----------------------------------------------------
    # Explicit registrations:
    # add_action('admin_post_eduflow_xxx', ...)
    # -----------------------------------------------------
    registered_actions.update(
        re.findall(
            r"admin_post_(eduflow_[A-Za-z0-9_]+)",
            text
        )
    )

    # -----------------------------------------------------
    # Dynamic registrations such as:
    #
    # foreach(array('save_batch','batch_status') as $action){
    #   add_action('admin_post_eduflow_'.$action,...);
    # }
    #
    # or:
    #
    # add_action('admin_post_eduflow_demo_'.$action,...);
    # -----------------------------------------------------
    foreach_blocks = re.finditer(
        r"foreach\s*\(\s*array\s*\((.*?)\)\s*as\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*\)\s*\{(.*?)\}",
        text,
        re.S
    )

    for block in foreach_blocks:
        array_body = block.group(1)
        variable   = block.group(2)
        body       = block.group(3)

        items = re.findall(
            r"[\"']([A-Za-z0-9_]+)[\"']",
            array_body
        )

        if not items:
            continue

        prefix_patterns = [
            rf"admin_post_(eduflow_[A-Za-z0-9_]*?)['\"]\s*\.\s*\${re.escape(variable)}",
            rf"admin_post_(eduflow_[A-Za-z0-9_]*?)['\"]\s*\.\s*\(\s*\${re.escape(variable)}\s*\)",
        ]

        prefixes = []

        for pattern in prefix_patterns:
            prefixes.extend(
                re.findall(
                    pattern,
                    body
                )
            )

        for prefix in prefixes:
            for item in items:
                registered_actions.add(
                    prefix + item
                )

    # -----------------------------------------------------
    # Compact foreach() without braces can also appear.
    # Example:
    # foreach(array(...) as $action)
    #     add_action('admin_post_eduflow_'.$action,...);
    # -----------------------------------------------------
    compact_blocks = re.finditer(
        r"foreach\s*\(\s*array\s*\((.*?)\)\s*as\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*\)"
        r"\s*add_action\s*\(\s*[\"']admin_post_(eduflow_[A-Za-z0-9_]*?)[\"']\s*\.\s*\$[A-Za-z_][A-Za-z0-9_]*",
        text,
        re.S
    )

    for block in compact_blocks:
        items = re.findall(
            r"[\"']([A-Za-z0-9_]+)[\"']",
            block.group(1)
        )

        prefix = block.group(3)

        for item in items:
            registered_actions.add(
                prefix + item
            )


missing_actions = sorted(
    referenced_actions - registered_actions
)

for action in missing_actions:
    warnings.append(
        f"Admin action referenced but registration not found "
        f"by static scan: {action}"
    )

# ---------------------------------------------------------
# 8. AutoFlow double-trigger scan
# ---------------------------------------------------------
print("8) AUTOFLOW DOUBLE-TRIGGER")

orchestrator = (
    ROOT /
    "includes/class-production-orchestrator.php"
)

if orchestrator.exists():
    o = orchestrator.read_text(errors="ignore")

    old_auto = len(
        re.findall(
            r"EduFlow_Auto_Assignment_Service::"
            r"assign_student_from_admission",
            o
        )
    )

    corp_auto = len(
        re.findall(
            r"EduFlow_Corporate_Autoflow_Service::"
            r"sync_student_from_admission",
            o
        )
    )

    info.append(
        f"Production orchestrator old autoflow calls: {old_auto}"
    )

    info.append(
        f"Production orchestrator corporate autoflow calls: {corp_auto}"
    )

    if old_auto and corp_auto:
        warnings.append(
            "IMPORTANT: Both legacy Auto_Assignment_Service and "
            "Corporate_Autoflow_Service are called from approval flow. "
            "Review for duplicate batch assignment."
        )

# ---------------------------------------------------------
# 9. Raw DB ID UI scan
# ---------------------------------------------------------
print("9) RAW DATABASE ID UI")

raw_id_patterns = [
    "Student DB ID",
    "Batch DB ID",
    "Teacher DB ID",
    "Admission database ID",
]

for f in php_files:
    text = f.read_text(errors="ignore")

    for phrase in raw_id_patterns:
        if phrase.lower() in text.lower():
            warnings.append(
                f"Raw DB ID still exposed in UI: "
                f"{phrase} -> {f}"
            )

# ---------------------------------------------------------
# 10. Risky direct deletes
# ---------------------------------------------------------
print("10) DATA DELETION SAFETY")

for f in php_files:
    text = f.read_text(errors="ignore")

    if re.search(
        r"\$wpdb->delete\s*\(",
        text
    ):
        warnings.append(
            f"Direct database delete found; verify soft-delete policy: {f}"
        )

# ---------------------------------------------------------
# 11. Student lifecycle consistency
# ---------------------------------------------------------
print("11) STUDENT LIFECYCLE")

student_service = (
    ROOT /
    "includes/class-student-service.php"
)

if student_service.exists():
    st = student_service.read_text(errors="ignore")

    for required in [
        "approved",
        "active",
        "hold",
        "dropped",
        "completed",
        "cancelled",
    ]:
        if required not in st:
            warnings.append(
                f"Student lifecycle status not found: {required}"
            )

# ---------------------------------------------------------
# 12. Teacher lifecycle consistency
# ---------------------------------------------------------
print("12) TEACHER LIFECYCLE")

teacher_service = (
    ROOT /
    "includes/class-teacher-service.php"
)

if teacher_service.exists():
    tt = teacher_service.read_text(errors="ignore")

    for required in [
        "active",
        "on_leave",
        "paused",
        "inactive",
    ]:
        if required not in tt:
            warnings.append(
                f"Teacher lifecycle status not found: {required}"
            )

# ---------------------------------------------------------
# 13. Backup files accidentally packaged
# ---------------------------------------------------------
print("13) BACKUP / DEBUG FILES")

backup_files = [
    f
    for f in ROOT.rglob("*")
    if f.is_file()
    and (
        ".BACKUP" in f.name
        or ".BEFORE-" in f.name
        or "FINAL-BACKUP" in f.name
    )
]

if backup_files:
    warnings.append(
        f"{len(backup_files)} backup/debug files exist inside "
        "plugin folder and will be packaged in ZIP."
    )

# ---------------------------------------------------------
# RESULT
# ---------------------------------------------------------
print()
print("=" * 70)
print("AUDIT RESULT")
print("=" * 70)

for item in info:
    print(f"INFO    : {item}")

for item in warnings:
    print(f"WARNING : {item}")

for item in errors:
    print(f"ERROR   : {item}")

print()
print(
    f"Errors: {len(errors)} | "
    f"Warnings: {len(warnings)}"
)

if errors:
    print("QUALITY GATE: FAIL")
    sys.exit(1)

if warnings:
    print("QUALITY GATE: PASS WITH WARNINGS")
    sys.exit(0)

print("QUALITY GATE: CLEAN PASS")
sys.exit(0)
