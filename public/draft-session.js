const fields = ['title','summary','category','language','content_date','asset_id','cover_id','subtitle_en','subtitle_fr','subtitle_de','subtitle_it','subtitle_es'];

/** Revision and uncertainty rules shared by native tools and the inspector. */
export class DraftSession {
    constructor({values,saveKey,validate,submit,read}) {
        this.values={...values}; this.saveKey=saveKey; this.validate=validate;
        this.submit=submit; this.read=read; this.revision=0; this.prepared=null;
        this.phase='editing'; this.draft=null;
    }
    state() { return {values:{...this.values},revision:this.revision,prepared_revision:this.prepared,save_key:this.saveKey,phase:this.phase,draft:this.draft}; }
    edit(values) {
        this.values={...this.values,...values}; this.revision++; this.prepared=null;
        if (!['saving','uncertain','saved'].includes(this.phase)) this.phase='editing';
    }
    async prepare(patch) {
        if (['saving','uncertain','saved'].includes(this.phase)) throw new Error('Resolve the current save before preparing another draft.');
        if (Object.keys(patch).some(key=>!fields.includes(key))) throw new Error('Unsupported field.');
        const revision=this.revision; const candidate={...this.values,...patch};
        const result=await this.validate(candidate);
        if (!result.ok) throw new Error(Object.values(result.errors ?? {}).flat().join(' ') || 'Invalid metadata.');
        if (revision!==this.revision) throw new Error('The form changed during preparation. Read and prepare again.');
        this.values=candidate; this.revision++; this.prepared=this.revision; this.phase='prepared';
        return this.state();
    }
    async save({revision,save_key}) {
        if (this.phase==='saving') throw new Error('A save is already in progress.');
        if (this.phase==='uncertain') throw new Error('Reconcile this save before retrying.');
        if (this.phase==='saved') return this.draft;
        if (revision!==this.revision || this.prepared!==this.revision || save_key!==this.saveKey) throw new Error('Prepare the current form revision before saving.');
        this.phase='saving';
        try {
            const draft=await this.submit({...this.values},this.saveKey);
            if (!draft?.id || draft.status!=='draft' || draft.published!==false) throw new Error('Save result has no persisted draft proof.');
            this.draft=draft; this.phase='saved'; return draft;
        } catch(error) {
            // Explicit server rejection proves no write; a transport failure does not.
            this.phase=error.definite ? 'prepared' : 'uncertain';
            throw error;
        }
    }
    async reconcile() {
        if (this.phase==='saving') throw new Error('A save is already in progress.');
        const draft=await this.read(this.saveKey);
        if (draft) { this.draft=draft; this.phase='saved'; }
        else if (this.phase==='uncertain') { this.phase='prepared'; }
        return draft;
    }
}
