'use strict';

const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

const script=fs.readFileSync(path.join(__dirname,'..','resources','views','hooks','chart-script.blade.php'),'utf8')
    .match(/<script>([\s\S]*?)<\/script>/)[1];
const strokes=[];
class Context {
    setTransform() {}
    fillRect() {}
    fillText() {}
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
const root={
    dataset:{},
    querySelectorAll:()=>[],
    querySelector:selector=>({
        '[data-akv-chart="history_in"]':wrap,
        '[data-akv-legend="history_in"]':legend,
        '.akv-chart-payload':{textContent:JSON.stringify({in:chart})}
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
