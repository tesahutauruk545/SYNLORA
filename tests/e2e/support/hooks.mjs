const MOCK = new URL('./mock-mediapipe.mjs', import.meta.url).href;
export async function resolve(specifier, context, next) {
    if (specifier === '@mediapipe/tasks-vision') return { url: MOCK, shortCircuit: true };
    return next(specifier, context);
}
