'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const s=fs.readFileSync('paper-workspace.js','utf8'),start=s.indexOf('function buildPackage(d)'),end=s.indexOf('async function render',start);
assert(start>=0&&end>start,'buildPackage present');
class Node{constructor(tag){this.tag=tag;this.children=[];this.textContent='';this.className='';this.classList={add:(name)=>{this.className=(this.className+' '+name).trim();}};}append(...items){this.children.push(...items);} }
const make=(tag,text,klass)=>{const n=new Node(tag);if(text!==undefined&&text!==null)n.textContent=String(text);if(klass)n.className=klass;return n;};
const isChoice=q=>['single','multiple','true_false'].includes(q.type),createForm=()=>new Node('form'),location={href:'https://urovia.test/paper-workspace.html'};
const build=new Function('make','isChoice','createForm','location','URL',s.slice(start,end)+'\nreturn buildPackage;')(make,isChoice,createForm,location,URL);
const qs=[
 {type:'single',text:'Один ответ',points:1,options:[{text:'A'},{text:'B'}]},
 {type:'multiple',text:'Несколько',points:2,options:[{text:'A'},{text:'B'},{text:'C'}]},
 {type:'true_false',text:'Верно/неверно',points:1,options:[{text:'Верно'},{text:'Неверно'}]},
 {type:'order',text:'Порядок',points:2,structured:{items:[{key:'1',text:'X'},{key:'2',text:'Y'}]}},
 {type:'matching',text:'Соответствие',points:2,structured:{left:[{key:'L1',text:'1'},{key:'L2',text:'2'}],right:[{key:'R1',text:'A'},{key:'R2',text:'B'}]}},
 {type:'text',text:'Краткий',points:1,structured:{answer_hint:'Событие или год'}},
 {type:'number',text:'Число',points:1},
 {type:'correction',text:'Исправьте',points:1,structured:{original_text:'Ошибочный текст'}},
 {type:'essay',text:'Развёрнутый',points:3}
];
const d={assignment:{title:'Проверка типов',subject_name:'История'},student:{first_name:'Тест',last_name:'Ученик',class_name:'7А'},variant_label:'A',code:'0123456789abcdef0123456789abcdef',questions:qs};
const packet=build(d),sheet=packet.children[0];function flatten(n){return [n.textContent||'',...(n.children||[]).flatMap(flatten)].join(' ');}
const text=flatten(sheet);
assert(text.includes('2*.'),'multi-choice question heading is marked');
assert(text.includes('* Выберите все правильные варианты.'),'multi-choice instruction');
assert(text.includes('Выберите один вариант ответа.'),'single choice instruction');
assert(text.includes('Запишите последовательность букв элементов.'),'order instruction');
assert(text.includes('Запишите пары, например 1-A, 2-B.'),'matching instruction');
assert(text.includes('Подсказка: Событие или год'),'short-answer hint preserved');
assert(text.includes('Запишите числовой ответ.'),'numeric instruction');
assert(text.includes('Исправьте ошибку и запишите правильный вариант.'),'correction instruction');
assert(text.includes('Дайте развёрнутый ответ.'),'essay instruction');
console.log('PAPER print output: all supported question types and directions OK');
