import {DraftSession} from './draft-session.js';

const boot=JSON.parse(document.querySelector('#page-bootstrap').dataset.state);
const form=document.querySelector('#video-form');
const requiredFields=['title','summary','category','language','content_date','asset_id'];
const fields=[...requiredFields,'cover_id','subtitle_en','subtitle_fr','subtitle_de','subtitle_it','subtitle_es'];
const errors=document.querySelector('#form-errors');
const eventList=document.querySelector('#tool-events');
const output=document.querySelector('#tool-result');
const pendingName=`video-workspace-pending:${boot.workspace}`;
let activeSignal;
const formValues=()=>Object.fromEntries(fields.map(name=>[name,form.elements[`video[${name}]`].value]));
function body(values,key) {
    const data=new FormData(form);
    for (const name of fields) data.set(`video[${name}]`,values[name]);
    data.set('video[save_key]',key); return data;
}
async function post(path,values,key) {
    const response=await fetch(path,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-Video-Workspace':'webmcp'},body:body(values,key),signal:activeSignal});
    const result=await response.json();
    if (!response.ok) {
        const error=new Error(result.error || Object.values(result.errors??{}).flat().join(' ') || 'The server rejected this request.');
        error.definite=response.status>=400 && response.status<500; throw error;
    }
    return result;
}
async function read(query) {
    const response=await fetch(`/api/draft?${new URLSearchParams(query)}`,{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store',signal:activeSignal});
    if (!response.ok) throw new Error('Draft read failed. The save outcome remains uncertain.');
    return (await response.json()).draft;
}
const session=new DraftSession({values:formValues(),saveKey:boot.save_key,
    validate:async values=>{
        try {return await post('/content/prepare',values,session.saveKey);}
        catch(error){if(error.definite)return {ok:false,errors:{form:error.message}};throw error;}
    },
    submit:async (values,key)=>{sessionStorage.setItem(pendingName,key);return (await post('/content/new',values,key)).draft;},
    read:key=>read({save_key:key}),
});
const pending=sessionStorage.getItem(pendingName);
if (pending) {session.saveKey=pending;session.phase='uncertain';}
function state() {
    const current=session.state();
    return {...current,choices:boot.choices,assets:boot.assets,attachments:boot.attachments,missing_fields:requiredFields.filter(name=>!current.values[name])};
}
function render() {
    for (const name of fields) form.elements[`video[${name}]`].value=session.values[name];
    const asset=boot.assets.find(item=>item.id===session.values.asset_id);
    if (asset) {
        const preview=document.querySelector('#asset-preview');
        if (!preview.src.endsWith(asset.url)) preview.src=asset.url;
        const cover=boot.attachments.find(item=>item.id===session.values.cover_id);
        if(cover)preview.poster=cover.url;else preview.removeAttribute('poster');
        document.querySelector('#asset-info').textContent=`${asset.label} · ${asset.duration} seconds · Server-inspected asset`;
    }
    form.querySelector('[type=submit]').disabled=['saving','uncertain','saved'].includes(session.phase);
    if (session.phase==='saved') {
        sessionStorage.removeItem(pendingName);
        const box=document.querySelector('#save-result');box.replaceChildren();box.className='success';
        const heading=document.createElement('strong');heading.textContent='Draft saved and verified';box.append(heading);
        const proof=document.createElement('p');proof.textContent=`ID ${session.draft.id} · Unpublished`;box.append(proof);
        const link=document.createElement('a');link.href=session.draft.url;link.textContent='Open saved draft →';box.append(link);
    } else if (session.phase==='uncertain') errors.textContent='A previous save may have completed. Read the draft by its save key before retrying.';
}
function event(name,source,result,failed=false) {
    const row=document.createElement('li');row.textContent=`${source} · ${name} · ${failed?'error':'complete'}`;eventList.append(row);
    output.textContent=JSON.stringify(result,null,2);
    document.dispatchEvent(new CustomEvent('video-workspace-tool',{detail:{name,source,result,failed}}));
}
const definitions=[
    {name:'get_video_content_form_state',description:'Read the visible video form, real choices, owned assets and save phase.',inputSchema:{type:'object',properties:{},additionalProperties:false},readOnly:true,run:async()=>state()},
    {name:'prepare_video_content_draft',description:'Validate metadata on the server, then atomically prepare the visible form. Does not save.',inputSchema:{type:'object',properties:Object.fromEntries(fields.map(name=>[name,{type:'string'}])),additionalProperties:false},run:async input=>{await session.prepare(input);render();return state();}},
    {name:'save_video_content_draft',description:'Save the prepared current revision as an unpublished draft through the existing Symfony form.',inputSchema:{type:'object',properties:{revision:{type:'integer'},save_key:{type:'string'}},required:['revision','save_key'],additionalProperties:false},run:async input=>{const draft=await session.save(input);render();return {draft};}},
    {name:'get_video_content_draft',description:'Read a workspace-owned persisted draft by ID or reconcile an uncertain save by save key.',inputSchema:{type:'object',properties:{id:{type:'string'},save_key:{type:'string'}},oneOf:[{required:['id']},{required:['save_key']}],additionalProperties:false},readOnly:true,run:async input=>{
        if (!!input.id===!!input.save_key) throw new Error('Supply exactly one draft ID or save key.');
        const draft=input.save_key===session.saveKey ? await session.reconcile() : await read(input);
        render();return {draft};
    }},
];
async function execute(definition,input,source,options={}) {
    activeSignal=options.signal;
    errors.textContent='';
    try {const result=await definition.run(input);event(definition.name,source,result);return JSON.stringify(result);}
    catch(error){render();errors.textContent=error.message;event(definition.name,source,{error:error.message},true);throw error;}
    finally {activeSignal=undefined;}
}
for (const name of fields) form.elements[`video[${name}]`].addEventListener('input',()=>{session.edit(formValues());render();});
form.addEventListener('submit',event=>{
    if (['saving','uncertain','saved'].includes(session.phase)) {event.preventDefault();errors.textContent='Resolve the current draft before another save.';}
});
document.querySelector('#execute-tool').addEventListener('click',async()=>{
    const definition=definitions.find(item=>item.name===document.querySelector('#tool-name').value);
    try {await execute(definition,JSON.parse(document.querySelector('#tool-input').value),'Inspector');}
    catch(error){errors.textContent=error.message;}
});
document.querySelector('#clear-events').addEventListener('click',()=>{eventList.replaceChildren();output.textContent='No tools executed yet.';});
const native=document.modelContext ?? navigator.modelContext;
const status=document.querySelector('#native-status');
if (native?.registerTool) {
    try {
        for (const definition of definitions) await native.registerTool({name:definition.name,description:definition.description,inputSchema:definition.inputSchema,
            annotations:{readOnlyHint:!!definition.readOnly,untrustedContentHint:true,consequentialHint:false},
            execute:(input,options)=>execute(definition,input,'Native WebMCP',options)});
        status.textContent='Native WebMCP available · 4 page tools registered';status.dataset.native='true';
    } catch(error) {status.textContent=`Native registration failed: ${error.message}`;status.dataset.native='false';}
} else {status.textContent='Native WebMCP unavailable · Use a compatible browser/client or the regular form';status.dataset.native='false';}
render();
