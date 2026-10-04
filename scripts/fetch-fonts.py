"""Download the project's pinned open font assets from Google Fonts source."""
from pathlib import Path
from urllib.request import urlopen
from urllib.parse import quote

root=Path(__file__).resolve().parents[1]/'public'/'fonts'
root.mkdir(parents=True,exist_ok=True)
for folder,name in [('sourcesans3','SourceSans3'),('sourcecodepro','SourceCodePro')]:
    for source,target in [(name+'[wght].ttf',name+'.ttf'),('OFL.txt',name+'-OFL.txt')]:
        url='https://raw.githubusercontent.com/google/fonts/main/ofl/'+folder+'/'+quote(source)
        with urlopen(url,timeout=30) as response: (root/target).write_bytes(response.read())
        print('Downloaded',target)
