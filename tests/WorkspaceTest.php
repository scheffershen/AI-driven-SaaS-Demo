<?php
namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WorkspaceTest extends WebTestCase
{
    public function testLandingAndCsrf(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('button', 'Start a demo workspace');
        $client->request('POST', '/workspace/start', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testManualFormPersistsOnlyDraftAndRejectsBadCsrf(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');
        $client->submit($crawler->selectButton('Start a demo workspace')->form());
        $client->followRedirect();
        $crawler = $client->request('GET', '/content/new');
        $form = $crawler->selectButton('Save draft')->form([
            'video[title]' => 'Manual test', 'video[summary]' => 'A real draft.',
            'video[category]' => 'getting-started', 'video[language]' => 'en',
            'video[content_date]' => '2026-10-03', 'video[asset_id]' => 'sample',
        ]);
        $values = $form->getPhpValues();
        $bad = $values; $bad['video']['_token'] = 'invalid';
        $client->request('POST', '/content/new', $bad, [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/content/new', $values, [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(201);
        $saved = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($saved['draft']['published']);
        self::assertSame('draft', $saved['draft']['status']);
        $client->request('GET', $saved['draft']['url']);
        self::assertSelectorTextContains('h1', 'Manual test');
    }
    public function testConfiguredProxyUsesDistinctClientsAndIgnoresUntrustedHeaders(): void
    {
        $_ENV['TRUSTED_PROXIES']='127.0.0.7';
        try {
            $client=self::createClient();
            foreach(['192.0.2.31','192.0.2.32'] as $ip) {
                $crawler=$client->request('GET','/',[],[],['REMOTE_ADDR'=>'127.0.0.7','HTTP_X_FORWARDED_FOR'=>$ip]);
                self::assertSame($ip,$client->getRequest()->getClientIp());
                $client->submit($crawler->selectButton('Start a demo workspace')->form(),[],['REMOTE_ADDR'=>'127.0.0.7','HTTP_X_FORWARDED_FOR'=>$ip]);
                self::assertResponseRedirects('/workspace');
            }
            $client->request('GET','/',[],[],['REMOTE_ADDR'=>'192.0.2.99','HTTP_X_FORWARDED_FOR'=>'192.0.2.88']);
            self::assertSame('192.0.2.99',$client->getRequest()->getClientIp());
        }finally{unset($_ENV['TRUSTED_PROXIES']);\Symfony\Component\HttpFoundation\Request::setTrustedProxies([],0);}
    }
    public function testDuplicateVideoNamesAndReplacementAttachmentsStaySelectable(): void
    {
        $client=self::createClient();$crawler=$client->request('GET','/');
        $client->submit($crawler->selectButton('Start a demo workspace')->form());$client->followRedirect();
        $workspace=$client->getRequest()->getSession()->get('workspace_id');
        $store=self::getContainer()->get(\App\Workspace\WorkspaceStore::class);
        $assets=self::getContainer()->get(\App\Video\AssetService::class);$ids=[];
        for($i=0;$i<2;$i++) {
            $path=tempnam(sys_get_temp_dir(),'duplicate');copy(dirname(__DIR__).'/var/sample/welcome.mp4',$path);
            $ids[]=$assets->upload($workspace,new \Symfony\Component\HttpFoundation\File\UploadedFile($path,'same.mp4',null,null,true))['id'];
        }
        foreach(['cover','caption'] as $kind)foreach(['f','a'] as $letter) {
            $id=str_repeat($letter==='f'?($kind==='cover'?'f':'e'):($kind==='cover'?'a':'b'),32);
            $store->db()->insert('attachments',['id'=>$id,'workspace_id'=>$workspace,'asset_id'=>'sample','kind'=>$kind,'language'=>$kind==='cover'?'':'fr','filename'=>$id.($kind==='cover'?'.jpg':'.vtt'),'label'=>$kind==='cover'?'same.jpg':'same.vtt','bytes'=>1,'mime'=>$kind==='cover'?'image/jpeg':'text/vtt']);
        }
        $crawler=$client->request('GET','/content/new');
        $options=$crawler->filter('#video_asset_id option')->extract(['value']);
        self::assertCount(3,$options);foreach($ids as $id)self::assertContains($id,$options);
        self::assertSame(str_repeat('a',32),$crawler->filter('#video_cover_id option[selected]')->attr('value'));
        self::assertSame(str_repeat('b',32),$crawler->filter('#video_subtitle_fr option[selected]')->attr('value'));
    }
}
