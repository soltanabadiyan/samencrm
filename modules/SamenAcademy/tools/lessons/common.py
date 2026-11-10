import os
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '../../layouts/v7/modules/SamenAcademy/resources/content/lessons')
IMG = 'layouts/v7/modules/SamenAcademy/resources/content/img/'
os.makedirs(OUT, exist_ok=True)

def fig(shot, caption, callouts=()):
    li = ''.join(f'<li>{c}</li>' for c in callouts)
    ol = f'<ol class="callouts">{li}</ol>' if callouts else ''
    return f'<figure class="shot"><img src="{IMG}{shot}.webp" alt="{caption}" width="1440" height="900"><figcaption>{caption}{ol}</figcaption></figure>'

def flow(steps, cls=''):
    """steps: str | (title, desc) | (title, desc, css class: decision / end)"""
    out = []
    for st in steps:
        if isinstance(st, str): st = (st,)
        t, d, c = (tuple(st) + ('', ''))[:3]
        out.append(f'<li class="{c}"><b>{t}</b>{d}</li>' if c else f'<li><b>{t}</b>{d}</li>')
    return f'<ol class="flow {cls}">' + ''.join(out) + '</ol>'

def todo(id_, items):
    return f'<ul class="todo" data-id="{id_}">' + ''.join(f'<li><span>{i}</span></li>' for i in items) + '</ul>'

def quiz(items):
    return ''.join(f'<details class="quiz"><summary>{q}</summary><p>{a}</p></details>' for q, a in items)

def goals(items):
    return '<ul class="goals">' + ''.join(f'<li>{i}</li>' for i in items) + '</ul>'

def calc(name):
    return f'<div data-calc="{name}"></div>'

def write(id_, title, body):
    html = f'<h2>{title}</h2>\n' + body.strip() + '\n'
    open(os.path.join(OUT, id_ + '.html'), 'w', encoding='utf8').write(html)
    return id_
