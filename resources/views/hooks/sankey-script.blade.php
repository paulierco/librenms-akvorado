<script>
(function () {
    'use strict';
    // Self-contained vector renderer: no external JavaScript or CDN. Values are
    // installed as text nodes (never inserted as HTML) and queries are server scoped.
    const ns='http://www.w3.org/2000/svg';
    const palette=['#198bca','#e96570','#28a486','#9469db','#e79e38','#2973e1','#d76fa9','#62a448','#14adc8','#bc7064','#657fcb','#c68b29'];
    // SVG fills are hardcoded, so dark mode must supply its own inks and halo stroke.
    // Read them from the panel's CSS variables (dark overrides live in panel.blade.php).
    function svgTheme(root) {
        const v = getComputedStyle(root);
        const pick = (name, fallback) => {
            const value = v.getPropertyValue(name).trim();
            return value === '' ? fallback : value;
        };
        return {
            heading: pick('--akv-ink', '#42556e'),
            label: pick('--akv-ink', '#26374c'),
            halo: pick('--akv-card-bg', '#f8fafc'),
            edge: pick('--akv-card-bg', '#ffffff')
        };
    }
    function svgEl(tag,attributes,text){
        const item=document.createElementNS(ns,tag);
        Object.entries(attributes||{}).forEach(([name,value])=>item.setAttribute(name,String(value)));
        if(text!==undefined)item.textContent=String(text);
        return item;
    }
    function bps(n){
        return n>=1e12?(n/1e12).toFixed(2)+' Tbps':n>=1e9?(n/1e9).toFixed(2)+' Gbps':n>=1e6?(n/1e6).toFixed(2)+' Mbps':n>=1e3?(n/1e3).toFixed(1)+' Kbps':n.toFixed(0)+' bps';
    }
    function validAddress(value){
        return /^\d{1,3}(?:\.\d{1,3}){3}$/.test(value) || (/^[0-9a-f:.]+$/i.test(value) && value.includes(':'));
    }
    function action(dimension,value,ports){
        // Placeholders must never become drill targets: the unresolved-interface label and
        // Akvorado's Other remainder are aggregates, not clickable identities.
        if(!value || value==='Other' || value==='source port unknown' || value==='destination port unknown')return null;
        if(dimension==='SrcAddr' && validAddress(value))return ['src',value];
        if(dimension==='DstAddr' && validAddress(value))return ['dst',value];
        if(dimension==='DstPort' && /^\d{1,5}$/.test(value) && Number(value)<=65535)return ['dst_port',value];
        if((dimension==='InIfName' || dimension==='OutIfName') && Object.prototype.hasOwnProperty.call(ports,value) && Number.isSafeInteger(Number(ports[value])) && Number(ports[value])>0)return ['port',String(ports[value])];
        return null;
    }
    function truncate(value,length){return value.length<=length?value:value.slice(0,length-1)+'…';}
    function render(root,raw){
        const t=svgTheme(root);
        const frame=root.querySelector('[data-akv-sankey]');if(!frame || !raw || !raw.diagram)return;
        const diagram=raw.diagram, ports=raw.ports||{};
        const dimensions=Array.isArray(diagram.dimensions)?diagram.dimensions:[];
        const rows=Array.isArray(diagram.rows)?diagram.rows:[];
        const stages=dimensions.length;
        if(stages<2 || stages>4 || rows.length===0)return;
        const layers=dimensions.map(()=>new Map());
        const links=new Map();
        let total=0;
        rows.slice(0,40).forEach(row=>{
            const w=Number(row.bps),values=row.values;
            if(!Number.isFinite(w)||w<=0||w>1e16||!Array.isArray(values)||values.length!==stages)return;
            total+=w;
            for(let i=0;i<stages;i++){
                const name=String(values[i]).slice(0,120);
                if(!layers[i].has(name))layers[i].set(name,{name,weight:0,index:i});
                layers[i].get(name).weight+=w;
            }
            for(let i=0;i<stages-1;i++){
                const from=String(values[i]).slice(0,120),to=String(values[i+1]).slice(0,120);
                const key=JSON.stringify([i,from,to]);
                if(!links.has(key))links.set(key,{stage:i,from,to,weight:0});
                links.get(key).weight+=w;
            }
        });
        if(total<=0)return;
        const maxNodes=Math.max(...layers.map(col=>col.size));
        const W=1150,H=Math.max(390,Math.min(820,Math.round(maxNodes*29+90)));
        const top=46,bottom=22,gap=11,nodeW=13;
        const svg=frame.querySelector('svg');svg.replaceChildren();
        svg.setAttribute('viewBox','0 0 '+W+' '+H);
        const series=layers.map(layer=>Array.from(layer.values()).sort((a,b)=>b.weight-a.weight||a.name.localeCompare(b.name)));
        const scale=Math.max(0.00000000000001,Math.min(...series.map(nodes=>(H-top-bottom-Math.max(0,nodes.length-1)*gap)/total)));
        const stageX=i=>25+i*(W-75)/(stages-1);
        const lookup=layers.map(()=>new Map());
        series.forEach((nodes,stage)=>{
            const occupied=nodes.reduce((sum,node)=>sum+node.weight*scale,0)+Math.max(0,nodes.length-1)*gap;
            let current=top+Math.max(0,(H-top-bottom-occupied)/2);
            nodes.forEach((node,j)=>{
                node.y=current;node.height=node.weight*scale;node.x=stageX(stage);
                node.leftUsed=0;node.rightUsed=0;
                node.color=palette[(j+stage*3)%palette.length];
                lookup[stage].set(node.name,node);
                current+=node.height+gap;
            });
            svg.appendChild(svgEl('text',{x:stageX(stage),y:23,'font-size':13,'font-weight':700,fill:t.heading},dimensions[stage]));
        });
        const under=svgEl('g',{'fill-opacity':'.42'});
        const edges=Array.from(links.values()).sort((a,b)=>a.stage-b.stage || series[a.stage].findIndex(n=>n.name===a.from)-series[b.stage].findIndex(n=>n.name===b.from) || b.weight-a.weight);
        const baseUrl=root.dataset.akvBaseUrl;
        function navigate(actions){
            if(!baseUrl || !actions.length)return;
            const url=new URL(baseUrl,window.location.href);
            url.searchParams.set('section','sankey');
            url.searchParams.set('sankey_view',String(root.dataset.akvSankeyView||'ports'));
            actions.forEach(([key,val])=>url.searchParams.set(key,val));
            window.location.assign(url.toString());
        }
        function interactive(element,actions,title){
            if(!baseUrl||!actions.length)return;
            element.style.cursor='pointer';element.setAttribute('role','link');element.setAttribute('tabindex','0');
            element.setAttribute('aria-label',title+' — filter investigation');
            element.addEventListener('click',()=>navigate(actions));
            element.addEventListener('keydown',e=>{if(e.key==='Enter' || e.key===' '){e.preventDefault();navigate(actions);}});
        }
        edges.forEach(edge=>{
            const from=lookup[edge.stage].get(edge.from),to=lookup[edge.stage+1].get(edge.to);
            if(!from||!to)return;
            const thickness=Math.max(0.15,edge.weight*scale);
            const x1=from.x+nodeW,x2=to.x;
            const y1=from.y+from.rightUsed,y2=to.y+to.leftUsed;
            from.rightUsed+=thickness;to.leftUsed+=thickness;
            const cx=x1+(x2-x1)*0.48;
            const path=`M${x1},${y1} C${cx},${y1} ${cx},${y2} ${x2},${y2} L${x2},${y2+thickness} C${cx},${y2+thickness} ${cx},${y1+thickness} ${x1},${y1+thickness} Z`;
            const ribbon=svgEl('path',{d:path,fill:from.color,stroke:'none'});
            ribbon.appendChild(svgEl('title',{},edge.from+' → '+edge.to+' · '+bps(edge.weight)));
            const actions=[action(dimensions[edge.stage],edge.from,ports),action(dimensions[edge.stage+1],edge.to,ports)].filter(Boolean);
            // One chart cannot select two different port IDs. A ribbon with
            // InIfName -> OutIfName defaults to its input endpoint; individual
            // node rectangles can still drill into either side.
            if(actions.length===2 && actions[0][0]==='port' && actions[1][0]==='port')actions.pop();
            interactive(ribbon,actions,edge.from+' to '+edge.to);
            ribbon.addEventListener('mouseenter',()=>ribbon.setAttribute('fill-opacity','0.93'));
            ribbon.addEventListener('mouseleave',()=>ribbon.setAttribute('fill-opacity','0.58'));
            under.appendChild(ribbon);
        });
        svg.appendChild(under);
        const nodesGroup=svgEl('g');
        series.forEach((nodes,stage)=>nodes.forEach(node=>{
            const g=svgEl('g');
            const y=node.y;
            const rect=svgEl('rect',{x:node.x,y,width:nodeW,height:Math.max(1,node.height),rx:1.5,fill:node.color,stroke:t.edge,'stroke-width':.55});
            g.appendChild(rect);
            const isLast=stage===stages-1;
            const x=isLast?node.x-8:node.x+nodeW+7;
            const label=svgEl('text',{x,y:y+Math.max(3,node.height/2),'font-size':11,'font-weight':600,fill:t.label,'dominant-baseline':'middle','text-anchor':isLast?'end':'start',stroke:t.halo,'stroke-width':2.6,'paint-order':'stroke'},truncate(node.name,isLast?17:20));
            g.appendChild(label);
            g.appendChild(svgEl('title',{},dimensions[stage]+': '+node.name+' · '+bps(node.weight)));
            interactive(g,[action(dimensions[stage],node.name,ports)].filter(Boolean),dimensions[stage]+' '+node.name);
            nodesGroup.appendChild(g);
        }));
        svg.appendChild(nodesGroup);
    }
    document.querySelectorAll('.akv-viz').forEach(root=>{
        const container=root.querySelector('.akv-sankey-payload');if(!container)return;
        try { render(root,JSON.parse(container.textContent)); }
        catch(e){const frame=root.querySelector('[data-akv-sankey]');if(frame){frame.textContent='Unable to render Sankey visualization; ranked data remains available in the table below.';}}
    });
})();
</script>
