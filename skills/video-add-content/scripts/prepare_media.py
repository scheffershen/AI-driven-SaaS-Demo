"""Local video preparation. Agent translates/writes; this helper preserves timings."""
from pathlib import Path
import argparse, json, subprocess, math

LANGUAGES=('en','fr','de','it','es')

def run(arguments):
    return subprocess.run(arguments,check=True,capture_output=True,text=True).stdout

def write_json(path,data):
    path.write_text(json.dumps(data,ensure_ascii=False,indent=2),encoding='utf-8')

def stamp(seconds):
    milliseconds=round(seconds*1000)
    hours,rest=divmod(milliseconds,3600000);minutes,rest=divmod(rest,60000);sec,ms=divmod(rest,1000)
    return f'{hours:02}:{minutes:02}:{sec:02}.{ms:03}'

def prepare(args):
    video=args.video.resolve(strict=True)
    info=json.loads(run(['ffprobe','-v','error','-show_format','-show_streams','-of','json',str(video)]))
    duration=float(info['format']['duration'])
    if not math.isfinite(duration) or not 0<duration<=7200 or not any(s['codec_type']=='video' for s in info['streams']):
        raise ValueError('A video shorter than two hours is required.')
    if not any(s['codec_type']=='audio' for s in info['streams']):
        raise ValueError('No audio stream. Ask for a transcript; do not invent captions.')
    args.output.mkdir(parents=True,exist_ok=False)
    cover=args.output/'cover.jpg'
    run(['ffmpeg','-v','error','-ss',str(min(duration*.2,duration-.05)),'-i',str(video),'-frames:v','1','-vf','scale=1280:-2','-q:v','2',str(cover)])
    from faster_whisper import WhisperModel
    model=WhisperModel(args.model,device='cpu',compute_type='int8',cpu_threads=4)
    segments,metadata=model.transcribe(str(video),language=args.language,beam_size=5,vad_filter=True)
    cues=[]
    for segment in segments:
        start=round(max(0,segment.start),3);end=round(min(duration,segment.end),3);text=segment.text.strip()
        if end>start and text:cues.append({'id':len(cues)+1,'start':start,'end':end,'text':text})
    if not cues:raise ValueError('No speech detected. Do not invent subtitles for silent/music-only content.')
    manifest={'video':str(video),'duration':duration,'source_language':metadata.language,'cover':str(cover.resolve()),'cues':cues}
    write_json(args.output/'transcript.json',manifest)
    template={'title':'','description':'','translations':{language:[{'id':cue['id'],'text':cue['text'] if language==metadata.language else ''} for cue in cues] for language in LANGUAGES}}
    write_json(args.output/'editorial-template.json',template)
    print(json.dumps({'status':'transcribed','manifest':str((args.output/'transcript.json').resolve()),'editorial_template':str((args.output/'editorial-template.json').resolve()),'cover':str(cover.resolve()),'cues':len(cues)}))

def finalize(args):
    manifest=json.loads((args.output/'transcript.json').read_text(encoding='utf-8'))
    editorial=json.loads(args.editorial.read_text(encoding='utf-8'))
    title=editorial.get('title');description=editorial.get('description')
    if not isinstance(title,str) or not 0<len(title.strip())<=120 or not isinstance(description,str) or not 0<len(description.strip())<=4000:
        raise ValueError('Provide a nonempty title <=120 and description <=4000 characters.')
    cues=manifest['cues'];outputs={}
    for language in LANGUAGES:
        translations=editorial.get('translations',{}).get(language)
        if not isinstance(translations,list) or len(translations)!=len(cues):raise ValueError(f'Missing/incomplete {language} translation.')
        lines=['WEBVTT','']
        for cue,translation in zip(cues,translations):
            text=translation.get('text')
            if translation.get('id')!=cue['id'] or not isinstance(text,str) or not text.strip() or '\x00' in text or '-->' in text or '\n\n' in text:
                raise ValueError(f'Invalid {language} cue {cue["id"]}. Preserve IDs and translate every cue.')
            if not 0<=cue['start']<cue['end']<=manifest['duration']+.001:raise ValueError('Invalid source cue timing.')
            lines.extend([str(cue['id']),f'{stamp(cue["start"])} --> {stamp(cue["end"])}',text.strip(),''])
        target=args.output/f'subtitles_{language}.vtt'
        if target.exists():raise FileExistsError(f'Refusing to overwrite {target}')
        outputs[language]=(target,'\n'.join(lines)+'\n')
    result_path=args.output/'content.json'
    if result_path.exists():raise FileExistsError(f'Refusing to overwrite {result_path}')
    for target,text in outputs.values():target.write_text(text,encoding='utf-8')
    result={'status':'complete','video':manifest['video'],'cover':manifest['cover'],'title':title.strip(),'summary':description.strip(),'duration':manifest['duration'],'subtitles':{language:str(target.resolve()) for language,(target,_) in outputs.items()}}
    write_json(result_path,result);print(json.dumps(result,ensure_ascii=False))

def main():
    parser=argparse.ArgumentParser();commands=parser.add_subparsers(dest='command',required=True)
    prep=commands.add_parser('prepare');prep.add_argument('video',type=Path);prep.add_argument('--output',type=Path,required=True);prep.add_argument('--model',default='small');prep.add_argument('--language',default=None)
    finish=commands.add_parser('finalize');finish.add_argument('--output',type=Path,required=True);finish.add_argument('--editorial',type=Path,required=True)
    args=parser.parse_args()
    if args.command=='prepare':prepare(args)
    else:finalize(args)

if __name__=='__main__':main()
