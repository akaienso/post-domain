#!/usr/bin/env python3
"""Extract prescribed files from a plan task. Development helper, not shipped."""
import re, sys, os, json

def blocks(plan_path):
    body = open(plan_path).read()
    # split into tasks
    tasks = []
    marks = list(re.finditer(r'^### Task (\d+):(.*)$', body, re.M))
    for i, m in enumerate(marks):
        end = marks[i+1].start() if i+1 < len(marks) else len(body)
        tasks.append((int(m.group(1)), m.group(2).strip(), body[m.start():end]))
    return tasks

def files_in(task_body):
    out = []
    # "Create `path`:" or "Replace `path` with...:" followed by a fenced block
    pat = re.compile(r'(Create|Replace) `([^`]+)`[^\n]*:\n+(?:<!--[^\n]*-->\n+)?```(php|json|xml|yaml|yml|bash|markdown|text)\n(.*?)^```', re.M|re.S)
    for m in pat.finditer(task_body):
        out.append({'verb': m.group(1), 'path': m.group(2), 'lang': m.group(3), 'code': m.group(4)})
    return out

if __name__ == '__main__':
    plan, task_no = sys.argv[1], int(sys.argv[2])
    mode = sys.argv[3] if len(sys.argv) > 3 else 'list'
    for no, title, body in blocks(plan):
        if no != task_no: continue
        fs = files_in(body)
        if mode == 'list':
            print(f"Task {no}: {title}")
            for f in fs:
                print(f"  [{f['verb']:7}] {f['lang']:8} {f['path']}  ({len(f['code'].splitlines())} lines)")
        elif mode in ('tests','src','all'):
            for f in fs:
                is_test = f['path'].startswith('tests/')
                if mode == 'tests' and not is_test: continue
                if mode == 'src' and is_test: continue
                if f['verb'] != 'Create' or not re.match(r'^(src|tests|bin)/', f['path']):
                    print(f"SKIP(non-create-or-nonstd) {f['verb']} {f['path']}", file=sys.stderr); continue
                os.makedirs(os.path.dirname(f['path']) or '.', exist_ok=True)
                open(f['path'],'w').write(f['code'])
                print(f"wrote {f['path']}")
