'use strict';

const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

const script=fs.readFileSync(path.join(__dirname,'..','resources','views','hooks','chart-script.blade.php'),'utf8')
    .match(/<script>([\s\S]*?)<\/script>/)[1];
const strokes=[];
const axisLabels=[];
class Context {
    setTransform() {}
    fillRect() {}
    fillText(text) { axisLabels.push(text); }
    fill() {}
    arc() {}
    beginPath() { this.path=[]; }
    moveTo(){ this.path.push('move'); }
    lineTo(){ this.path.push('line'); }
    setLineDash() {}
    stroke(){ strokes.push({color:this.strokeStyle, path:this.path}); }
}
const context=new Context();
const handlers={};
const canvas={
    getContext:()=>context,
    getBoundingClientRect:()=>({width:261, left:0, top:0}),
    addEventListener:(event,handler)=>{handlers[event]=handler;}
};
const tooltip={style:{}};
const wrap={querySelector:selector=>selector==='canvas'?canvas:tooltip};
const legend={appendChild(){}};
const chart={
    labels:['2026-11-21 12:00:00 UTC','2026-11-21 12:01:00 UTC','2026-11-21 12:02:00 UTC'],
    unit:'l2bps',
    lines:true,
    series:[
        {label:'LibreNMS SNMP', values:[100,null,50]},
        {label:'Akvorado L2', values:[0,10,0], dashed:true}
    ]
};
let chartName='history_in';
let payload={in:chart};
const root={
    dataset:{},
    querySelectorAll:()=>[],
    querySelector:selector=>({
        ['[data-akv-chart="'+chartName+'"]']:wrap,
        ['[data-akv-legend="'+chartName+'"]']:legend,
        '.akv-chart-payload':{textContent:JSON.stringify(payload)}
    })[selector]
};
const document={
    querySelectorAll:()=>[root],
    createElement:()=>({style:{},appendChild(){},setAttribute(){},addEventListener(){}}),
    createTextNode:text=>text
};
vm.runInNewContext(script,{document,window:{addEventListener(){}},getComputedStyle:()=>({getPropertyValue:()=>''})});
assert(strokes.some(({path})=>path.length===2 && path.every(op=>op==='move')),
    'the missing SNMP bucket breaks the line into separate points');
handlers.mousemove({clientX:156,clientY:25});
assert.match(tooltip.textContent,/LibreNMS SNMP: Unavailable/);
handlers.mousemove({clientX:65,clientY:25});
assert.match(tooltip.textContent,/Akvorado L2: 0 bps/);
console.log('PASS historical renderer breaks lines at gaps and labels measured zero');

chartName='exporter_health';
payload={exporterHealth:{
    labels:chart.labels,
    unit:'percent',
    lines:true,
    series:[
        {label:'Unknown ingress', values:[0,null,62.5]},
        {label:'Unknown egress', values:[1.23,0,50], dashed:true}
    ]
}};
axisLabels.length=0;
strokes.length=0;
vm.runInNewContext(script,{document,window:{addEventListener(){}},getComputedStyle:()=>({getPropertyValue:()=>''})});
assert.deepEqual(axisLabels.slice(0,5),['0%','25%','50%','75%','100%']);
assert(strokes.some(({path})=>path.length===2 && path.every(op=>op==='move')));
handlers.mousemove({clientX:156,clientY:25});
assert.match(tooltip.textContent,/Unknown ingress: Unavailable/);
handlers.mousemove({clientX:65,clientY:25});
assert.match(tooltip.textContent,/Unknown ingress: 0%/);
assert.match(tooltip.textContent,/Unknown egress: 1\.23%/);
console.log('PASS exporter history uses a fixed percent scale and distinguishes gaps from 0%');
