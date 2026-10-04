"""Verify complete exports and refusal of incomplete agent translations."""
from pathlib import Path
import tempfile,json,subprocess,sys,unittest

SCRIPT=Path(__file__).resolve().parents[1]/'skills/video-add-content/scripts/prepare_media.py'
class MediaPreparationTest(unittest.TestCase):
    def run_export(self,folder,editorial):
        path=folder/'editorial.json';path.write_text(json.dumps(editorial),encoding='utf-8')
        return subprocess.run([sys.executable,str(SCRIPT),'finalize','--output',str(folder),'--editorial',str(path)],capture_output=True,text=True)
    def test_complete_exports_preserve_timing_and_refuse_overwrite(self):
        with tempfile.TemporaryDirectory() as directory:
            folder=Path(directory)
            (folder/'transcript.json').write_text(json.dumps({'video':'fixture.mp4','cover':'cover.jpg','duration':3.984,'cues':[{'id':1,'start':.25,'end':3.7,'text':'Hello'}]}))
            editorial={'title':'Hello','description':'A real greeting.','translations':{lang:[{'id':1,'text':'Hello'}] for lang in ['en','fr','de','it','es']}}
            result=self.run_export(folder,editorial);self.assertEqual(result.returncode,0,result.stderr)
            for lang in ['en','fr','de','it','es']:self.assertIn('00:00:00.250 --> 00:00:03.700',(folder/f'subtitles_{lang}.vtt').read_text())
            self.assertEqual(json.loads(result.stdout)['status'],'complete')
            result=self.run_export(folder,editorial);self.assertNotEqual(result.returncode,0);self.assertIn('Refusing to overwrite',result.stderr)
    def test_incomplete_translation_writes_no_caption_files(self):
        with tempfile.TemporaryDirectory() as directory:
            folder=Path(directory)
            (folder/'transcript.json').write_text(json.dumps({'video':'fixture.mp4','cover':'cover.jpg','duration':2,'cues':[{'id':1,'start':0,'end':1,'text':'Hello'}]}))
            editorial={'title':'Hello','description':'Greeting','translations':{'en':[{'id':1,'text':'Hello'}]}}
            result=self.run_export(folder,editorial);self.assertNotEqual(result.returncode,0)
            self.assertEqual(list(folder.glob('*.vtt')),[]);self.assertFalse((folder/'content.json').exists())

if __name__=='__main__':unittest.main()
