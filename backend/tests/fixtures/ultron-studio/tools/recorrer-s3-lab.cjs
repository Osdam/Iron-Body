'use strict';

// Complete local S3 walk. HTTP model calls are synthetic Responses replies;
// only VM execution and JSON files are available, no fetch/http client.
const fs = require('fs');
const vm = require('vm');
const has = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
const clone = value => JSON.parse(JSON.stringify(value));
const PREPARE = 'Preparar Responses · ';
const HTTP = 'Responses API · ';
const COMMIT = 'Preparar Commit';

function runCode(code, input, lookup) {
  return new vm.Script('(function(){' + code + '\n})()').runInNewContext({
    $json: input, $input:{first:()=>({json:input}),all:()=>[{json:input}]}, $:lookup,
    $execution:{id:'4242'},
  },{timeout:10000})[0].json;
}

function evaluate(expression, input, lookup) {
  if (!expression.startsWith('={{') || !expression.endsWith('}}')) throw new Error('Expresión n8n inválida.');
  return new vm.Script('(' + expression.slice(3,-2) + ')').runInNewContext({$json:input,$:lookup},{timeout:5000});
}

function walk(spec) {
  const flow = spec.flowObject || JSON.parse(fs.readFileSync(spec.flow,'utf8'));
  const nodes = Object.fromEntries(flow.nodes.map(node=>[node.name,node]));
  const ran = {'Pedir Contexto al CRM':clone(spec.decide),'Normalizar Evento':clone(spec.event)};
  const lookup = name=>({first:()=>{
    if (has(ran,name)) return {json:ran[name]};
    if (spec.mode==='undefined') return undefined;
    throw new Error('Nodo no ejecutado: '+name);
  }});
  ran['Elegir Prompts']=runCode(nodes['Elegir Prompts'].parameters.jsCode,clone(spec.decide),lookup);
  const queue=[{name:PREPARE+'Strategist',input:ran['Elegir Prompts']}];
  const physicalOrder=[];
  const order=[];
  const bodies={};
  const sent={};
  const requests={};
  const finish=(status,extra={})=>({status,order,physicalOrder,sent,requests,bodies,ran,commit:null,at:null,error:null,...extra});
  while(queue.length) {
    const {name,input}=queue.shift();
    if (physicalOrder.includes(name)||physicalOrder.length>70) throw new Error('S3 repite un nodo o no termina.');
    physicalOrder.push(name);
    const node=nodes[name];
    if(!node) throw new Error('Nodo ausente en S3.');
    if(!name.startsWith(PREPARE)&&!name.startsWith(HTTP)) order.push(name);
    let outputs;
    try {
      if(name===COMMIT) {
        const commit=runCode(node.parameters.jsCode,clone(input),lookup);
        ran[name]=commit;
        return finish('done',{commit});
      }
      if(node.type==='n8n-nodes-base.code') {
        const output=runCode(node.parameters.jsCode,clone(input),lookup);
        outputs=[output];
        if(name.startsWith(PREPARE)) {
          const model=name.slice(PREPARE.length);
          const body=output.ultron_request.body;
          requests[model]=body;
          sent[model]=body.instructions===undefined
            ? {system:body.input[0].content[0].text,user:body.input[1].content[0].text}
            : {system:body.instructions,user:body.input[0].content[0].text};
        }
      } else if(node.type==='n8n-nodes-base.httpRequest') {
        const body=JSON.parse(evaluate(node.parameters.jsonBody,input,lookup));
        if(name.startsWith(HTTP)) {
          const model=name.slice(HTTP.length);
          if(!has(spec.pins,model)) throw new Error('Llamada al modelo no prevista.');
          const pin=clone(spec.pins[model]);
          const output=pin.output[0].content[0].text;
          outputs=[{status:'completed',error:null,output:[{type:'message',content:[{type:'output_text',text:JSON.stringify(output)}]}],usage:pin.usage}];
        } else {
          bodies[name]=body;
          if(!has(spec.pins,name)) throw new Error('Comprobación no prevista.');
          outputs=[clone(spec.pins[name])];
        }
      } else if(node.type==='n8n-nodes-base.if') {
        const condition=node.parameters.conditions.conditions[0];
        const value=evaluate(condition.leftValue,input,lookup);
        const yes=condition.operator.type==='boolean'?value===true:value===condition.rightValue;
        outputs=yes?[input,null]:[null,input];
      } else throw new Error('Tipo de nodo no previsto.');
    } catch(error) {
      return finish('failed',{at:name,error:String(error&&error.message)});
    }
    ran[name]=outputs.find(value=>value!==null);
    outputs.forEach((value,index)=>{
      if(value!==null) for(const edge of (flow.connections[name]?.main||[])[index]||[]) queue.push({name:edge.node,input:value});
    });
  }
  throw new Error('S3 no llega al commit.');
}

try {
  const spec=JSON.parse(fs.readFileSync(0,'utf8'));
  process.stdout.write(JSON.stringify(walk(spec)));
} catch(error) {
  process.stderr.write(String(error&&error.message||'Error del arnés S3.'));
  process.exitCode=2;
}
