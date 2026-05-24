import { Injectable } from '@angular/core';
import * as faceapi from '@vladmandic/face-api';

export interface FaceReference {
  user_id: number;
  member_id: number;
  member_uuid: string;
  name: string;
  plan?: string | null;
  face_url: string;
  captured_at?: string | null;
}

export interface FaceMatch {
  userId: number;
  name: string;
  distance: number;
  confidence: number;
}

interface CachedDescriptor {
  userId: number;
  name: string;
  descriptor: Float32Array;
}

/**
 * Reconocimiento facial 100% en el navegador con @vladmandic/face-api.
 *
 * Flujo:
 * 1. loadModels() — descarga los modelos (tiny detector, landmarks, recognition)
 *    desde /assets/face-api-models/. Se carga una sola vez.
 * 2. buildReferences(refs) — descarga la imagen biométrica de cada miembro,
 *    extrae el descriptor (vector 128-d) y lo deja en memoria para matching.
 * 3. matchFromVideo(video) — detecta el rostro del frame actual y devuelve
 *    el miembro más parecido si la distancia euclidiana está por debajo del
 *    umbral (≈ 0.5 ≈ 78% de confianza).
 */
@Injectable({ providedIn: 'root' })
export class FaceRecognitionService {
  private readonly modelsUrl = '/assets/face-api-models';
  private readonly matchThreshold = 0.5;
  private readonly detectorOptions = new faceapi.TinyFaceDetectorOptions({
    inputSize: 320,
    scoreThreshold: 0.5,
  });

  private modelsReady = false;
  private modelsLoadingPromise: Promise<void> | null = null;
  private references: CachedDescriptor[] = [];
  private matcher: faceapi.FaceMatcher | null = null;

  async loadModels(): Promise<void> {
    if (this.modelsReady) return;
    if (this.modelsLoadingPromise) return this.modelsLoadingPromise;

    this.modelsLoadingPromise = (async () => {
      await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(this.modelsUrl),
        faceapi.nets.faceLandmark68Net.loadFromUri(this.modelsUrl),
        faceapi.nets.faceRecognitionNet.loadFromUri(this.modelsUrl),
      ]);
      this.modelsReady = true;
    })();

    try {
      await this.modelsLoadingPromise;
    } finally {
      this.modelsLoadingPromise = null;
    }
  }

  /**
   * Descarga y procesa los rostros de referencia. Si una imagen falla o no
   * tiene rostro detectable, se omite ese miembro (no rompe el flujo global).
   * Devuelve la cantidad de referencias cargadas con éxito.
   */
  async buildReferences(refs: FaceReference[]): Promise<number> {
    await this.loadModels();

    const results = await Promise.all(
      refs.map((ref) => this.extractReferenceDescriptor(ref).catch(() => null)),
    );

    this.references = results.filter((r): r is CachedDescriptor => r !== null);
    this.rebuildMatcher();

    return this.references.length;
  }

  referenceCount(): number {
    return this.references.length;
  }

  /**
   * Procesa el frame actual del <video> y devuelve el mejor match si la
   * distancia es menor al umbral. Devuelve null si no hay rostro detectado
   * o si nadie supera el umbral.
   */
  async matchFromVideo(video: HTMLVideoElement): Promise<FaceMatch | null> {
    if (!this.modelsReady || !this.matcher) return null;
    if (video.readyState < 2 || video.videoWidth === 0) return null;

    const detection = await faceapi
      .detectSingleFace(video, this.detectorOptions)
      .withFaceLandmarks()
      .withFaceDescriptor();

    if (!detection) return null;

    const best = this.matcher.findBestMatch(detection.descriptor);
    if (best.label === 'unknown') return null;

    const userId = Number(best.label);
    const reference = this.references.find((r) => r.userId === userId);
    if (!reference) return null;

    return {
      userId,
      name: reference.name,
      distance: best.distance,
      confidence: Math.max(0, 1 - best.distance),
    };
  }

  reset(): void {
    this.references = [];
    this.matcher = null;
  }

  private async extractReferenceDescriptor(ref: FaceReference): Promise<CachedDescriptor | null> {
    const image = await this.loadImage(ref.face_url);
    const detection = await faceapi
      .detectSingleFace(image, this.detectorOptions)
      .withFaceLandmarks()
      .withFaceDescriptor();

    if (!detection) return null;

    return {
      userId: ref.user_id,
      name: ref.name,
      descriptor: detection.descriptor,
    };
  }

  private loadImage(url: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.crossOrigin = 'anonymous';
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error(`No se pudo cargar la imagen ${url}`));
      image.src = url;
    });
  }

  private rebuildMatcher(): void {
    if (this.references.length === 0) {
      this.matcher = null;
      return;
    }

    const labeled = this.references.map(
      (ref) =>
        new faceapi.LabeledFaceDescriptors(String(ref.userId), [ref.descriptor]),
    );
    this.matcher = new faceapi.FaceMatcher(labeled, this.matchThreshold);
  }
}
