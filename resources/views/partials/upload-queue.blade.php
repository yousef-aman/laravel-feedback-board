{{-- Alpine members shared by the report form and both composers; printed inside x-data="{ … }", so no double quotes. --}}
uploading: 0,
progress: 0,
dragging: false,
queue: [],
draining: false,
enqueue(files) {
    for (const file of Array.from(files || [])) {
        this.queue.push(file);
        this.uploading++;
    }
    this.drain();
},
async drain() {
    if (this.draining) return;
    this.draining = true;
    while (this.queue.length > 0) {
        const file = this.queue.shift();
        this.progress = 0;
        /* Each file resolves on finish, error or cancel, so a failure never stalls the rest. The next one starts
           from this continuation, never inside Livewire's callback (that runs before its own queue check). */
        await new Promise((done) => this.$wire.upload('upload', file, done, done, (event) => { this.progress = event.detail.progress }, done));
        this.uploading--;
    }
    this.progress = 0;
    this.draining = false;
},
named(file) {
    const types = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp' };
    const generic = ['', 'image.png', 'image.jpg', 'image.jpeg', 'image.gif', 'image.webp'];
    const extension = types[file.type];
    if (! extension || ! generic.includes(file.name || '')) return file;
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const stamp = now.getFullYear() + pad(now.getMonth() + 1) + pad(now.getDate()) + '-' + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds());
    return new File([file], 'screenshot-' + stamp + '.' + extension, { type: file.type });
},
pasted(event) {
    const data = event.clipboardData;
    let files = Array.from((data && data.files) || []);
    if (files.length === 0) {
        files = Array.from((data && data.items) || []).filter((item) => item.kind === 'file').map((item) => item.getAsFile()).filter(Boolean);
    }
    if (files.length === 0) return;
    /* Office and spreadsheet copies carry a picture next to their text: in a text field the text wins. */
    const tag = event.target && event.target.tagName;
    if ((tag === 'INPUT' || tag === 'TEXTAREA') && data.getData('text/plain') !== '') return;
    event.preventDefault();
    /* Only pasted images get the screenshot name; picked and dropped files keep theirs. */
    const named = files.map((file) => this.named(file));
    /* Several images pasted together share one stamp, so the 2nd, 3rd... renamed file gets -2, -3. */
    const used = {};
    this.enqueue(named.map((file, index) => {
        if (file === files[index] || ! file.name.startsWith('screenshot-')) return file;
        used[file.name] = (used[file.name] || 0) + 1;
        if (used[file.name] === 1) return file;
        const dot = file.name.lastIndexOf('.');
        return new File([file], file.name.slice(0, dot) + '-' + used[file.name] + file.name.slice(dot), { type: file.type });
    }));
},
picked(event) {
    this.enqueue(event.target.files);
    event.target.value = '';
},
dropped(event) {
    this.dragging = false;
    this.enqueue(event.dataTransfer ? event.dataTransfer.files : []);
},
