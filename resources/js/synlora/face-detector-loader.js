/** Memuat MediaPipe Face Detector (lokal di browser). Mencoba tiap alamat model, GPU lalu CPU. */
export async function loadFaceDetector({ wasmPath, modelPaths }) {
    const { FaceDetector, FilesetResolver } = await import('@mediapipe/tasks-vision');
    const vision = await FilesetResolver.forVisionTasks(wasmPath);

    let lastErr = null;
    for (const modelAssetPath of modelPaths) {
        for (const delegate of ['GPU', 'CPU']) {
            try {
                return await FaceDetector.createFromOptions(vision, {
                    baseOptions: { modelAssetPath, delegate },
                    runningMode: 'VIDEO',
                    minDetectionConfidence: 0.5,
                });
            } catch (err) {
                lastErr = err;
            }
        }
    }
    throw lastErr || new Error('Model deteksi wajah tidak dapat dimuat');
}
