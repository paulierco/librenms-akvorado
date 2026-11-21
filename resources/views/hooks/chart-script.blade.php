<script>
(function () {
    'use strict';
    const colors = ['#2486ed','#12a987','#7d58dd','#eea73d','#e75b6b','#13b7d9','#7b8b9f','#ca71bb'];
    const roots = Array.from(document.querySelectorAll('.akv-viz[data-akv-initialized="false"]'));
    // Charts are canvas-drawn, so CSS alone cannot theme them. Read the palette from the
    // container's CSS variables (the plugin sets dark values under <html class="dark">),
    // with light fallbacks so the chart still draws if variables are unavailable.
    function theme(root) {
        const v = getComputedStyle(root);
        const pick = (name, fallback) => {
            const value = v.getPropertyValue(name).trim();
            return value === '' ? fallback : value;
        };
        return {
            surface: pick('--akv-card-bg', '#ffffff'),
            ink: pick('--akv-ink', '#1f2d42'),
            muted: pick('--akv-muted', '#7a8285'),
            rule: pick('--akv-rule', '#e8edf4'),
            donutHole: pick('--akv-card-bg', '#ffffff')
        };
    }
    function finite(v) { return Number.isFinite(Number(v)) ? Math.max(0, Number(v)) : 0; }
    function niceNumber(v, kind) {
        if (kind === 'percent') {
            return v.toFixed(Number.isInteger(v) ? 0 : 2)+'%';
        }
        if (kind === 'pps') {
            return v >= 1e6 ? (v/1e6).toFixed(2)+' Mpps' : v >= 1000 ? (v/1000).toFixed(1)+' kpps' : v.toFixed(0)+' pps';
        }
        if (kind === 'bytes') {
            return v >= 1e12 ? (v/1e12).toFixed(2)+' TB' : v >= 1e9 ? (v/1e9).toFixed(2)+' GB' : v >= 1e6 ? (v/1e6).toFixed(1)+' MB' : v >= 1000 ? (v/1000).toFixed(1)+' kB' : v.toFixed(0)+' B';
        }
        return v >= 1e9 ? (v/1e9).toFixed(2)+' Gbps' : v >= 1e6 ? (v/1e6).toFixed(1)+' Mbps' : v >= 1000 ? (v/1000).toFixed(1)+' Kbps' : v.toFixed(0)+' bps';
    }
    function stamp(str) {
        const txt=String(str||'').replace(/ UTC$/, '');
        return txt.length >= 16 ? txt.substring(5,16) : txt;
    }
    function setupCanvas(canvas, h) {
        const bounds = canvas.getBoundingClientRect();
        const width = Math.max(210, bounds.width || 300);
        const height = h || Math.max(190, bounds.height || 252);
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        canvas.width = Math.round(width*dpr); canvas.height = Math.round(height*dpr);
        const ctx=canvas.getContext('2d'); ctx.setTransform(dpr,0,0,dpr,0,0);
        return {ctx,width,height};
    }
    function chartFor(root,name,data,stacked) {
        const th=theme(root);
        const container=root.querySelector('[data-akv-chart="'+name+'"]');
        if (!container || !data || !Array.isArray(data.series) || !data.series.length || !Array.isArray(data.labels) || data.labels.length < (data.lines ? 1 : 2)) return;
        const canvas=container.querySelector('canvas');
        const tooltip=container.querySelector('.akv-tooltip');
        const legend=root.querySelector('[data-akv-legend="'+name+'"]');
        const active=data.series.map(()=>true);
        const series=data.series.map((s,i)=>({
            label:String(s.label),
            values:s.values.map(value=>(s.previous || data.lines) && value===null ? null : finite(value)).slice(0,data.labels.length),
            color:colors[(Number.isInteger(s.colorIndex) && s.colorIndex >= 0 ? s.colorIndex : i)%colors.length],
            lineOnly:s.previous===true || data.lines===true,
            dashed:s.previous===true || s.dashed===true
        }));
        let hover=-1;
        let dragStart=-1,dragEnd=-1;
        const timestamps = Array.isArray(data.timestamps) ? data.timestamps : [];
        const canZoom = name === 'main' && !!root.dataset.akvBaseUrl
            && timestamps.length === data.labels.length
            && timestamps.every(v => Number.isFinite(Number(v)) && Number(v) > 946684800);

        const xLeft=65,xRight=14,yTop=14,yBottom=32;
        const xAt=(i,n,w)=>(xLeft+(w-xLeft-xRight)*(n===1 ? .5 : i/(n-1)));
        function draw() {
            const {ctx,width:w,height:h}=setupCanvas(canvas,252);
            const n=data.labels.length, pH=h-yTop-yBottom;
            ctx.fillStyle=th.surface;ctx.fillRect(0,0,w,h);
            const totals=Array(n).fill(0);
            series.forEach((s,i)=>{if(active[i])s.values.forEach((v,j)=>{if(j<n) totals[j]+=v;});});
            const maxY=Math.max(1,stacked?Math.max(...totals):Math.max(...series.flatMap((s,i)=>active[i]?s.values:[]),1));
            const scaleMax=data.unit==='percent' ? 100 : maxY*1.13;
            const yAt=(v)=>yTop+pH*(1-v/scaleMax);
            ctx.lineWidth=1;ctx.font='11px sans-serif';ctx.textBaseline='middle';
            for(let i=0;i<=4;i++){
                const value=scaleMax*i/4,y=yAt(value);
                ctx.strokeStyle=th.rule;ctx.beginPath();ctx.moveTo(xLeft,y);ctx.lineTo(w-xRight,y);ctx.stroke();
                ctx.fillStyle=th.muted;ctx.textAlign='right';ctx.fillText(niceNumber(value,data.unit),xLeft-8,y);
            }
            const ticks=Math.min(5,n-1);
            for(let j=0;j<=ticks;j++){
                const ix=ticks===0 ? 0 : Math.round((n-1)*j/ticks),x=xAt(ix,n,w);
                ctx.fillStyle=th.muted;ctx.textAlign=ticks===0?'center':j===0?'left':j===ticks?'right':'center';
                ctx.fillText(stamp(data.labels[ix]),x,h-12);
            }
            const bottom=Array(n).fill(0);
            series.forEach((s,i)=>{
                if (!active[i]) return;
                if (s.lineOnly) {
                    ctx.beginPath();
                    let drawing=false;
                    s.values.forEach((value,j)=>{
                        if(value===null){drawing=false;return;}
                        const x=xAt(j,n,w),y=yAt(value);
                        if(drawing)ctx.lineTo(x,y);else ctx.moveTo(x,y);
                        drawing=true;
                    });
                    ctx.setLineDash(s.dashed ? [5,4] : []);
                    ctx.strokeStyle=s.color;ctx.lineWidth=1.8;ctx.stroke();ctx.setLineDash([]);
                    if (data.lines) {
                        ctx.fillStyle=s.color;
                        s.values.forEach((value,j)=>{
                            if(value!==null){
                                ctx.beginPath();ctx.arc(xAt(j,n,w),yAt(value),2,0,Math.PI*2);ctx.fill();
                            }
                        });
                    }
                    return;
                }
                const top=s.values.map((v,j)=>v+(stacked?bottom[j]||0:0));
                const base=stacked?bottom:Array(n).fill(0);
                ctx.beginPath();
                ctx.moveTo(xAt(0,n,w),yAt(base[0]));
                for(let j=0;j<n;j++)ctx.lineTo(xAt(j,n,w),yAt(top[j]||0));
                for(let j=n-1;j>=0;j--)ctx.lineTo(xAt(j,n,w),yAt(base[j]||0));
                ctx.closePath();ctx.fillStyle=s.color+(stacked?'a6':'20');ctx.fill();
                ctx.beginPath();top.forEach((v,j)=>{if(j===0)ctx.moveTo(xAt(j,n,w),yAt(v||0));else ctx.lineTo(xAt(j,n,w),yAt(v||0));});
                ctx.strokeStyle=s.color;ctx.lineWidth=1.8;ctx.stroke();
                if(stacked)for(let j=0;j<n;j++)bottom[j]=top[j]||0;
            });
            if(dragStart>=0 && dragEnd>=0) {
                const x1=xAt(Math.min(dragStart,dragEnd),n,w),x2=xAt(Math.max(dragStart,dragEnd),n,w);
                ctx.fillStyle='rgba(36,134,237,0.16)';ctx.fillRect(x1,yTop,Math.max(2,x2-x1),pH);
                ctx.strokeStyle='#2486ed';ctx.strokeRect(x1,yTop,Math.max(2,x2-x1),pH);
            }
            if (hover>=0 && hover<n) {
                const x=xAt(hover,n,w);
                ctx.beginPath();ctx.setLineDash([4,4]);ctx.moveTo(x,yTop);ctx.lineTo(x,h-yBottom);ctx.strokeStyle='#7b8ba4';ctx.stroke();ctx.setLineDash([]);
            }
        }
        series.forEach((s,i)=>{
            const b=document.createElement('button');b.type='button';b.className='akv-legend-item';b.setAttribute('aria-pressed','true');
            const dot=document.createElement('span');
            dot.className=data.lines ? 'akv-line-key' : 'akv-dot';
            if(data.lines){
                dot.style.borderTopColor=s.color;
                dot.style.borderTopStyle=s.dashed ? 'dashed' : 'solid';
            }else{
                dot.style.backgroundColor=s.color;
            }
            b.appendChild(dot);
            b.appendChild(document.createTextNode(s.label));
            b.addEventListener('click',()=>{active[i]=!active[i];b.setAttribute('aria-pressed',String(active[i]));draw();});legend.appendChild(b);
        });
        function pointerToIndex(e) {
            const rect=canvas.getBoundingClientRect();
            const ix=Math.round((e.clientX-rect.left-xLeft)/Math.max(1,rect.width-xLeft-xRight)*(data.labels.length-1));
            return Math.max(0,Math.min(data.labels.length-1,ix));
        }
        function navigateZoom(startIndex,endIndex) {
            if (!canZoom) return;
            const a=Math.min(Number(timestamps[startIndex]),Number(timestamps[endIndex]));
            const b=Math.max(Number(timestamps[startIndex]),Number(timestamps[endIndex]));
            const click=Math.abs(endIndex-startIndex)<2;
            // Click: center a ten-minute investigation, drag: use selected bounds.
            let start=click?a-300:a;
            let end=click?a+300:b;
            const current=Math.floor(Date.now()/1000);
            if(end>current)end=current;
            if(end-start<300)start=end-300;
            if(end-start>604800)start=end-604800;
            if(start<current-691200)start=current-691200;
            if(end-start<300 || end<=start)return;
            const url=new URL(root.dataset.akvBaseUrl,window.location.href);
            url.searchParams.set('start',String(Math.round(start)));
            url.searchParams.set('end',String(Math.round(end)));
            url.searchParams.set('section','traffic');
            window.location.assign(url.toString());
        }
        if(canZoom){
            canvas.addEventListener('pointerdown',function(e){
                if(e.button!==0 || e.pointerType==='touch')return;
                dragStart=pointerToIndex(e);dragEnd=dragStart;
                if(canvas.setPointerCapture)canvas.setPointerCapture(e.pointerId);
                draw();
            });
            canvas.addEventListener('pointermove',function(e){
                if(dragStart<0)return;dragEnd=pointerToIndex(e);draw();
            });
            canvas.addEventListener('pointerup',function(e){
                if(dragStart<0)return;
                const start=dragStart,end=pointerToIndex(e);
                dragStart=-1;dragEnd=-1;draw();navigateZoom(start,end);
            });
            canvas.addEventListener('pointercancel',function(){dragStart=-1;dragEnd=-1;draw();});
        }
        canvas.addEventListener('mousemove',function(e){
            const rect=canvas.getBoundingClientRect(),w=rect.width;
            hover=pointerToIndex(e);
            const lines=[String(data.labels[hover])];
            series.forEach((s,i)=>{
                if(!active[i])return;
                const value=s.values[hover];
                lines.push(s.label+': '+(s.lineOnly && value==null ? 'Unavailable' : niceNumber(value||0,data.unit)));
            });
            tooltip.textContent=lines.join('\n');tooltip.style.display='block';
            tooltip.style.left=Math.min(w-190,Math.max(10,e.clientX-rect.left+12))+'px';
            tooltip.style.top=Math.max(8,e.clientY-rect.top-60)+'px';
            draw();
        });
        canvas.addEventListener('mouseleave',()=>{hover=-1;tooltip.style.display='none';draw();});
        if('ResizeObserver' in window){new ResizeObserver(()=>draw()).observe(container);}else{window.addEventListener('resize',draw);}
        draw();
    }
    function donutFor(root,items) {
        const th=theme(root);
        const container=root.querySelector('[data-akv-donut]');if(!container || !Array.isArray(items) || !items.length)return;
        const canvas=container.querySelector('canvas');
        const legend=root.querySelector('[data-akv-donut-legend]');
        const safe=items.filter(it=>finite(it.value)>0).slice(0,8);
        const total=safe.reduce((s,x)=>s+finite(x.value),0);if(!total)return;
        function draw(){
            const {ctx,width:w,height:h}=setupCanvas(canvas,185);
            const x=w/2,y=h/2,r=Math.min(w,h)*.42;
            let angle=-Math.PI/2;
            safe.forEach((item,i)=>{const sweep=2*Math.PI*finite(item.value)/total;ctx.beginPath();ctx.moveTo(x,y);ctx.arc(x,y,r,angle,angle+sweep);ctx.closePath();ctx.fillStyle=colors[i%colors.length];ctx.fill();angle+=sweep;});
            ctx.beginPath();ctx.arc(x,y,r*.61,0,Math.PI*2);ctx.fillStyle=th.donutHole;ctx.fill();
            ctx.textAlign='center';ctx.fillStyle=th.ink;ctx.font='bold 18px sans-serif';ctx.fillText(niceNumber(total,'l3bps'),x,y-1);
            ctx.fillStyle=th.muted;ctx.font='10px sans-serif';ctx.fillText('ranked flows',x,y+15);
        }
        safe.forEach((item,i)=>{
            const row=document.createElement('div');row.className='akv-row';
            const left=document.createElement('span');const dot=document.createElement('span');dot.className='akv-dot';dot.style.backgroundColor=colors[i%colors.length];left.appendChild(dot);
            left.appendChild(document.createTextNode(String(item.label)));
            const right=document.createElement('strong');right.textContent=(100*finite(item.value)/total).toFixed(1)+'%';
            row.appendChild(left);row.appendChild(right);legend.appendChild(row);
        });
        if('ResizeObserver' in window){new ResizeObserver(draw).observe(container);}draw();
    }
    roots.forEach(root=>{
        root.dataset.akvInitialized='true';
        const tabs=Array.from(root.querySelectorAll('[data-akv-tab]'));
        const sections=Array.from(root.querySelectorAll('[data-akv-section]'));
        function activate(section) {
            if(!['traffic','talkers','flows','health','sankey'].includes(section))section='traffic';
            tabs.forEach(tab=>{
                tab.setAttribute('aria-selected',String(tab.dataset.akvTab===section));
            });
            sections.forEach(node=>{
                node.hidden = !String(node.dataset.akvSection||'').split(' ').includes(section);
            });
            if('Event' in window)window.dispatchEvent(new Event('resize'));
        }
        tabs.forEach(tab=>tab.addEventListener('click',function(){
            const section=tab.dataset.akvTab;
            if(section==='sankey' && root.dataset.akvSankeyLoaded!=='true' && root.dataset.akvBaseUrl){
                const url=new URL(root.dataset.akvBaseUrl,window.location.href);
                url.searchParams.set('section','sankey');
                url.searchParams.set('sankey_view','ports');
                window.location.assign(url.toString());
                return;
            }
            activate(section);
            if(window.history && window.history.replaceState) {
                const url=new URL(window.location.href);
                if(section==='traffic')url.searchParams.delete('section');
                else url.searchParams.set('section',section);
                window.history.replaceState(null,'',url.toString());
                // Also retain the active investigation section when a talker or
                // conversation link triggers the next server-side query.
                root.querySelectorAll('a[data-akv-filter-link]').forEach(function(link){
                    try{
                        const target=new URL(link.href,window.location.href);
                        if(section==='traffic')target.searchParams.delete('section');
                        else target.searchParams.set('section',section);
                        link.href=target.toString();
                    }catch(e){/* preserve link if a browser cannot parse it */}
                });
                // Update navigation URL used by click-to-zoom without losing filters.
                if(root.dataset.akvBaseUrl){const base=new URL(root.dataset.akvBaseUrl,window.location.href);
                    if(section==='traffic')base.searchParams.delete('section');
                    else base.searchParams.set('section',section);
                    root.dataset.akvBaseUrl=base.toString();}
            }
        }));
        activate(root.dataset.akvInitialSection||'traffic');
        const copyButton=root.querySelector('[data-akv-copy]');
        if(copyButton){copyButton.addEventListener('click',async function(){
            const input=root.querySelector('.akv-query-preview');if(!input)return;
            try{
                if(navigator.clipboard && navigator.clipboard.writeText){
                    await navigator.clipboard.writeText(input.value);
                    copyButton.textContent='Copied';
                }else{input.focus();input.select();copyButton.textContent='Selected — press Ctrl+C';}
            }catch(e){input.focus();input.select();copyButton.textContent='Selected — press Ctrl+C';}
        });}

        try {
            const data=JSON.parse(root.querySelector('.akv-chart-payload').textContent);
            chartFor(root,'main',data.main,false);
            chartFor(root,'ports',data.ports,true);
            chartFor(root,'member_in',data.member_in,false);
            chartFor(root,'member_out',data.member_out,false);
            chartFor(root,'history_in',data.in,false);
            chartFor(root,'history_out',data.out,false);
            chartFor(root,'exporter_health',data.exporterHealth,false);
            donutFor(root,data.donut);
        } catch(err) { /* charts are optional; do not break the LibreNMS page */ }
    });
})();
</script>
