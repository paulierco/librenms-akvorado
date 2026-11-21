// Run optionally with Node 18+; LibreNMS production does not require Node.
'use strict';
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync(path.join(__dirname,'../resources/views/hooks/sankey-script.blade.php'),'utf8').replace(/^<script>\s*/,'').replace(/\s*<\/script>\s*$/,'');
class SvgNode {
    constructor(tag){this.tag=tag;this.children=[];this.attrs={};this.style={};this.handlers={};this.textContent='';}
    setAttribute(name,value){this.attrs[name]=String(value);}
    appendChild(child){this.children.push(child);return child;}
    replaceChildren(...children){this.children=children;}
    addEventListener(name,fn){this.handlers[name]=fn;}
    querySelector(s){if(s==='svg')return this.children.find(x=>x.tag==='svg');return null;}
}
function run(dimensions, rows, ports){
    const svg=new SvgNode('svg'),frame=new SvgNode('div');frame.children=[svg];
    const payload={diagram:{dimensions,rows},ports};
    const json={textContent:JSON.stringify(payload)};
    const root={dataset:{akvBaseUrl:'https://librenms.test/device/22/akvorado?port=25&period=60&start=1763636400&end=1763638200',akvSankeyView:'ports'},querySelector(s){return s==='[data-akv-sankey]'?frame:s==='.akv-sankey-payload'?json:null;}};
    let destination=null;
    const doc={createElementNS(ns,tag){assert.equal(ns,'http://www.w3.org/2000/svg');return new SvgNode(tag);},querySelectorAll(s){return s==='.akv-viz'?[root]:[];}};
    vm.runInNewContext(source,{getComputedStyle(){return {getPropertyValue(){return '';}};},document:doc,window:{location:{href:'https://librenms.test/device/22/akvorado',assign(s){destination=s;}}},URL,Number,Object,Array,Map,Math,JSON,String});
    const all=[];function visit(el){all.push(el);el.children.forEach(visit);}visit(svg);
    return {svg,all,navigated:()=>destination};
}
const ports=run(['InIfName','OutIfName'],[
    {values:['1/1/1','1/1/24'],bps:750000000},
    {values:['1/1/2','1/1/24'],bps:450000000},
    {values:['Other','1/1/24'],bps:55000000},
],{'1/1/1':25,'1/1/24':48});
assert(ports.all.filter(x=>x.tag==='path').length===3,'expected three colored ribbons');
assert(ports.all.filter(x=>x.tag==='rect').length===4,'expected four node columns total');
assert(ports.all.some(x=>x.tag==='text'&&x.textContent==='InIfName'),'dimension heading');
const portRibbon=ports.all.find(x=>x.tag==='path'&&x.handlers.click);portRibbon.handlers.click();
assert(ports.navigated().includes('section=sankey')&&ports.navigated().includes('port=25'),'ribbon selects safe source port');
assert(ports.navigated().includes('start=1763636400'),'zoom retained');
const ips=run(['SrcAddr','DstAddr','DstPort'],[{values:['192.0.2.44','198.51.100.22','443'],bps:60000000}],{});
assert(ips.all.filter(x=>x.tag==='path').length===2,'three-dimensional map has two stage links');
ips.all.find(x=>x.tag==='path'&&x.handlers.click).handlers.click();
assert(ips.navigated().includes('src=192.0.2.44')&&ips.navigated().includes('dst=198.51.100.22'),'IP ribbon focuses both endpoints');
console.log('PASS Sankey renderer: node layout, colored ribbons, safe click-to-filter, query scope and three-stage support');
