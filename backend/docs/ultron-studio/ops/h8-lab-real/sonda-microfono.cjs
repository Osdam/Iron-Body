// SONDA LOCAL (sin ElevenLabs, sin sesión del CRM, sin gasto): ¿llega a WebAudio el audio del micrófono falso de Chrome?
// Abre la página pública de acceso del CRM (solo para tener un origen seguro), pide el micrófono con las restricciones
// que usa el cliente oficial 1.30.0 y mide la energía (RMS) segundo a segundo en un AudioContext de 8 kHz y en uno por
// defecto. No toca la aplicación ni la red de la API.
//   node sonda-microfono.cjs <audio.wav> [segundos]
'use strict';
const [, , F_AUDIO, SEG = '24'] = process.argv;
const puppeteer = require('/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body_Front/node_modules/puppeteer-core');
(async () => {
  const browser = await puppeteer.launch({
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true,
    args: ['--no-first-run', '--disable-extensions', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', ...(F_AUDIO === 'pitidos' ? [] : [`--use-file-for-fake-audio-capture=${F_AUDIO}`]), '--autoplay-policy=no-user-gesture-required', ...(process.env.EXTRA ? process.env.EXTRA.split(' ') : [])],
  });
  try {
    const ctx = await browser.createBrowserContext();
    await ctx.overridePermissions('https://ironbodyneiva.cloud', ['microphone']);
    const page = await ctx.newPage();
    page.on('console', (m) => console.log('  consola:', m.text().slice(0, 200)));
    await page.goto('https://ironbodyneiva.cloud/version.json', { waitUntil: 'domcontentloaded', timeout: 60000 });
    const medir = async (nombre, restricciones, frecuencia) => {
      const r = await page.evaluate(async (restr, frec, seg) => {
        const flujo = await navigator.mediaDevices.getUserMedia({ audio: restr });
        const pista = flujo.getAudioTracks()[0];
        const ac = new AudioContext(frec ? { sampleRate: frec } : {});
        await ac.resume();
        const fuente = ac.createMediaStreamSource(flujo);
        const an = ac.createAnalyser();
        an.fftSize = 2048;
        fuente.connect(an);
        const buf = new Float32Array(an.fftSize);
        const porSegundo = [];
        for (let s = 0; s < seg; s++) {
          let max = 0;
          for (let i = 0; i < 10; i++) {
            await new Promise((ok) => setTimeout(ok, 100));
            an.getFloatTimeDomainData(buf);
            let suma = 0;
            for (const v of buf) suma += v * v;
            max = Math.max(max, Math.sqrt(suma / buf.length));
          }
          porSegundo.push(Number(max.toFixed(3)));
        }
        const ajustes = pista.getSettings();
        pista.stop();
        await ac.close();
        return { estado: ac.state, frecuenciaContexto: ac.sampleRate, ajustes: { sampleRate: ajustes.sampleRate, echoCancellation: ajustes.echoCancellation, noiseSuppression: ajustes.noiseSuppression, autoGainControl: ajustes.autoGainControl, channelCount: ajustes.channelCount }, etiqueta: pista.label, porSegundo };
      }, restricciones, frecuencia, Number(SEG));
      console.log(`\n== ${nombre} ==`);
      console.log('  pista:', r.etiqueta, '· ajustes:', JSON.stringify(r.ajustes), '· contexto:', r.frecuenciaContexto, 'Hz');
      console.log('  RMS máximo por segundo:', r.porSegundo.join(' '));
      console.log('  segundos con señal (> 0,02):', r.porSegundo.filter((v) => v > 0.02).length, 'de', r.porSegundo.length);
    };
    await medir('como el cliente oficial (8 kHz, cancelación de eco, supresión de ruido, aislamiento de voz)', { sampleRate: { ideal: 8000 }, echoCancellation: true, noiseSuppression: true, voiceIsolation: true }, 8000);
    if (!process.env.SOLO_UNA) await medir('sin procesado (audio: true, contexto por defecto)', true, 0);
  } finally {
    await browser.close().catch(() => {});
  }
})().catch((e) => { console.log('ERROR', String(e.message || e).slice(0, 300)); process.exit(1); });
