'use strict';

// Execute one generated n8n Code node with synthetic inputs. No HTTP implementation.
const fs = require('fs');
const vm = require('vm');
try {
  const spec = JSON.parse(fs.readFileSync(0, 'utf8'));
  const context = {
    $json: spec.input,
    $input: {first: () => ({json: spec.input})},
    $: name => {
      if (!Object.prototype.hasOwnProperty.call(spec.nodes || {}, name)) throw new Error('Nodo no ejecutado.');
      return {first: () => ({json: spec.nodes[name]})};
    },
  };
  const result = new vm.Script('(function(){' + spec.code + '\n})()').runInNewContext(context, {timeout:3000});
  process.stdout.write(JSON.stringify({ok:true,result}));
} catch (error) {
  process.stdout.write(JSON.stringify({ok:false,error:String(error && error.message || 'Error de nodo.')}));
}
