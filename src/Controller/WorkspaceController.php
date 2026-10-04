<?php
namespace App\Controller;

use App\Video\{AssetService,AttachmentService,DraftService,VideoFormType,VideoInput};
use App\Workspace\{WorkspaceContext,WorkspaceStore};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{BinaryFileResponse,JsonResponse,Request,Response};
use Symfony\Component\Routing\Attribute\Route;

final class WorkspaceController extends AbstractController
{
    public function __construct(private WorkspaceContext $context,private WorkspaceStore $store,private DraftService $drafts,private AssetService $assets,private AttachmentService $attachments) {}

    #[Route('/health',name:'health',methods:['GET'])]
    public function health(): Response { return new Response('ok'); }

    #[Route('/',name:'landing',methods:['GET'])]
    public function landing(): Response { return $this->render('landing.html.twig'); }

    #[Route('/workspace/start',name:'workspace_start',methods:['POST'])]
    public function start(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('start_workspace',$request->request->get('_token'))) return new Response('Invalid CSRF token',403);
        try {
            $this->store->cleanup();
            $id=$this->store->create($request->getClientIp()??'local');
            $request->getSession()->migrate(true); $request->getSession()->set('workspace_id',$id);
            return $this->redirectToRoute('workspace');
        } catch (\DomainException $e) { return $this->failure($request,$e); }
    }

    #[Route('/workspace',name:'workspace',methods:['GET'])]
    public function workspace(Request $request): Response
    {
        try { $workspace=$this->context->requireWorkspace($request); return $this->render('workspace.html.twig',['drafts'=>$this->drafts->list($workspace)]); }
        catch (\DomainException $e) { return $this->failure($request,$e); }
    }

    #[Route('/content/new',name:'content_new',methods:['GET','POST'])]
    #[Route('/content/prepare',name:'content_prepare',methods:['POST'])]
    public function form(Request $request): Response
    {
        try {
            $workspace=$this->context->requireWorkspace($request);
            $assets=$this->assets->list($workspace); $choices=[];
            foreach ($assets as $index=>$asset) $choices[($index+1).' · '.$asset['label']]=$asset['id'];
            $attachmentChoices=[];
            foreach($this->attachments->list($workspace) as $index=>$attachment) {
                $field=$attachment['kind']==='cover'?'cover_id':'subtitle_'.$attachment['language'];
                $attachmentChoices[$field][($index+1).' · '.$attachment['label'].' · video '.$attachment['asset_id']]=$attachment['id'];
            }
            $key=bin2hex(random_bytes(16));
            $selected=(string)$request->query->get('asset_id','sample');if(!in_array($selected,array_values($choices),true))$selected='sample';
            $defaults=['title'=>'','summary'=>'','category'=>'','language'=>'','content_date'=>'','asset_id'=>$selected,...array_fill_keys(VideoInput::ATTACHMENTS,'')];
            foreach($this->attachments->list($workspace) as $attachment)if($attachment['asset_id']===$selected)$defaults[$attachment['kind']==='cover'?'cover_id':'subtitle_'.$attachment['language']]=$attachment['id'];
            $form=$this->createForm(VideoFormType::class, $defaults, ['asset_choices'=>$choices,'attachment_choices'=>$attachmentChoices,'save_key'=>$key,'action'=>'/content/new']);
            $form->handleRequest($request);
            if ($form->isSubmitted()) {
                $errors=[];
                if (!$form->isValid()) foreach ($form->getErrors(true) as $error) $errors[$error->getOrigin()->getName()][]=$error->getMessage();
                $payload=array_intersect_key($form->getData()??[],array_flip(VideoInput::FIELDS));
                foreach(VideoInput::ATTACHMENTS as $field)$payload[$field]=$payload[$field]??'';
                if (!$errors) $errors=$this->drafts->validate($workspace,$payload);
                if ($request->attributes->get('_route')==='content_prepare') return new JsonResponse(['ok'=>!$errors,'errors'=>$errors],$errors?422:200);
                if ($errors) {
                    if ($this->wantsJson($request)) return new JsonResponse(['ok'=>false,'errors'=>$errors],422);
                    return $this->render('form.html.twig',['form'=>$form,'assets'=>$assets,'errors'=>$errors,'boot'=>$this->bootstrap($workspace,$key,$form->getData()??[],$assets)],new Response(status:422));
                }
                $this->store->throttle('save:'.$workspace,30);
                $draft=$this->drafts->save($workspace,$payload,(string)$form->get('save_key')->getData());
                return $this->wantsJson($request) ? new JsonResponse(['ok'=>true,'draft'=>$draft],201) : $this->redirect($draft['url']);
            }
            return $this->render('form.html.twig',['form'=>$form,'assets'=>$assets,'errors'=>[],'boot'=>$this->bootstrap($workspace,$key,$form->getData(),$assets)]);
        } catch (\DomainException $e) { return $this->failure($request,$e); }
    }

    private function bootstrap(string $workspace,string $key,array $values,array $assets): array
    {
        $map=static fn(array $choices)=>array_map(static fn($label,$id)=>['id'=>$id,'label'=>$label],array_keys($choices),array_values($choices));
        return ['workspace'=>$workspace,'values'=>$values,'save_key'=>$key,'assets'=>$assets,'attachments'=>$this->attachments->list($workspace),'choices'=>['category'=>$map(VideoInput::CATEGORIES),'language'=>$map(VideoInput::LANGUAGES)]];
    }

    #[Route('/api/draft',name:'draft_read',methods:['GET'])]
    public function read(Request $request): Response
    {
        try {
            $workspace=$this->context->requireWorkspace($request);
            $id=$request->query->get('id'); $key=$request->query->get('save_key');
            if ((!$id&&!$key)||($id&&$key)) return new JsonResponse(['error'=>'Supply exactly one draft ID or save key.'],400);
            return new JsonResponse(['draft'=>$this->drafts->find($workspace,$id,$key)],headers:['Cache-Control'=>'private, no-store']);
        } catch (\DomainException $e) { return $this->failure($request,$e); }
    }

    #[Route('/content/{id}',name:'content_detail',requirements:['id'=>'[a-f0-9]{24}'],methods:['GET'])]
    public function detail(Request $request,string $id): Response
    {
        try {
            $workspace=$this->context->requireWorkspace($request); $draft=$this->drafts->find($workspace,$id,null);
            if (!$draft) throw $this->createNotFoundException('Draft not found in this workspace.');
            return $this->render('detail.html.twig',['draft'=>$draft],new Response(headers:['Cache-Control'=>'private, no-store']));
        } catch (\DomainException $e) { return $this->failure($request,$e); }
    }

    #[Route('/assets/upload',name:'asset_upload',methods:['POST'])]
    public function upload(Request $request): Response
    {
        try {
            $workspace=$this->context->requireWorkspace($request);
            if (!$this->isCsrfTokenValid('upload_video',$request->request->get('_token'))) return new JsonResponse(['error'=>'Invalid upload CSRF token.'],403);
            $this->store->throttle('upload:'.$workspace,10);
            $file=$request->files->get('video');
            if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) throw new \DomainException('Select an MP4 file.',422);
            $asset=$this->assets->upload($workspace,$file);
            return $this->wantsJson($request) ? new JsonResponse(['asset'=>$asset],201) : $this->redirectToRoute('content_new',['asset_id'=>$asset['id']]);
        } catch (\DomainException $e) { return $this->failure($request,$e); }
    }

    #[Route('/media/{id}',name:'asset_media',requirements:['id'=>'sample|[a-f0-9]{32}'],methods:['GET'])]
    public function media(Request $request,string $id): Response
    {
        try {
            $path=$this->assets->path($this->context->requireWorkspace($request),$id);
            if (!$path || !is_file($path)) throw $this->createNotFoundException('Video not found in this workspace.');
            return new BinaryFileResponse($path,headers:['Content-Type'=>'video/mp4','Cache-Control'=>'private, no-store']);
        } catch (\DomainException $e) { return $this->failure($request,$e); }
    }
    #[Route('/attachments/upload',name:'attachment_upload',methods:['POST'])]
    public function uploadAttachment(Request $request): Response
    {
        try {
            $workspace=$this->context->requireWorkspace($request);
            if(!$this->isCsrfTokenValid('upload_attachment',$request->request->get('_token')))return new JsonResponse(['error'=>'Invalid attachment CSRF token.'],403);
            $this->store->throttle('attachment:'.$workspace,30);
            $file=$request->files->get('attachment');
            if(!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile)throw new \DomainException('Select a cover image or VTT file.',422);
            $attachment=$this->attachments->upload($workspace,(string)$request->request->get('asset_id'),(string)$request->request->get('kind'),(string)$request->request->get('language',''),$file);
            return $this->wantsJson($request)?new JsonResponse(['attachment'=>$attachment],201):$this->redirectToRoute('content_new',['asset_id'=>$attachment['asset_id']]);
        }catch(\DomainException $e){return $this->failure($request,$e);}
    }
    #[Route('/attachments/{id}',name:'attachment_media',requirements:['id'=>'[a-f0-9]{32}'],methods:['GET'])]
    public function attachment(Request $request,string $id): Response
    {
        try {
            $row=$this->attachments->record($this->context->requireWorkspace($request),$id);
            if(!$row)throw $this->createNotFoundException('Attachment not found in this workspace.');
            return new BinaryFileResponse($this->store->dataDir.'/uploads/'.$row['filename'],headers:['Content-Type'=>$row['mime'],'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
        }catch(\DomainException $e){return $this->failure($request,$e);}
    }
    private function wantsJson(Request $request): bool { return str_contains($request->headers->get('Accept',''),'application/json'); }
    private function failure(Request $request,\DomainException $error): Response
    {
        $status=in_array($error->getCode(),[403,409,422,429],true)?$error->getCode():400;
        return $this->wantsJson($request) ? new JsonResponse(['ok'=>false,'error'=>$error->getMessage()],$status) : $this->render('error.html.twig',['message'=>$error->getMessage()],new Response(status:$status));
    }
}
