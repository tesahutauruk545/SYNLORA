// Pengganti @mediapipe/tasks-vision HANYA untuk uji otomatis di Node (tidak ada GPU/kamera di sana).
// Kode aplikasi memanggil API yang sama; "wajah ada/tidak" dikendalikan test lewat globalThis.__faces.
export const FilesetResolver = { forVisionTasks: async () => ({}) };
export const FaceDetector = {
    createFromOptions: async () => ({
        detectForVideo: () => ({ detections: globalThis.__faces ? [{ boundingBox: {} }] : [] }),
        close() {},
    }),
};
