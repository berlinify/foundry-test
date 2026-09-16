import re, sys
LAT = re.compile(r"[A-Za-z0-9][A-Za-z0-9 ._\-/:@+&%']*[A-Za-z0-9]|[A-Za-z0-9]")
unrev = lambda t: LAT.sub(lambda m: m.group(0)[::-1], t[::-1])

toks = [l.strip() for l in open(sys.argv[1], encoding='utf-8').read().split('\n') if l.strip()]
# whole stream reversed -> each visual line now reads logically, but lines come out last-first
flat = [unrev(t) for t in reversed(toks)]
text = ' '.join(flat)
text = text.replace('اطالع','اطلاع').replace('اطالع','اطلاع')  # lam-alef repair
# split into units on sentence/bullet boundaries, then restore document order
units = [u.strip() for u in re.split(r'(?<=[.؟!])\s+|\s+(?=•)', text) if u.strip()]
out = '\n\n'.join(reversed(units))
open(sys.argv[2],'w',encoding='utf-8').write(out)
print(f"tokens={len(toks)} units={len(units)}")
