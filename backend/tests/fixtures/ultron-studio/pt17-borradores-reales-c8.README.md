# Evidencia histórica PT-17 c8

`pt17-borradores-reales-c8.json` conserva los 25 casos de `docs/ultron-studio/ops/evidencia-h9/2026-11-24/pt17-medicion-c8/casos-c8.jsonl` del worktree `ultron-h8`. La fuente completa tiene SHA-256 `335d8fe19c6fc2775dd0d4a96062ff7134a440cd68693191a7e51a25cb51cd88`.

Se copiaron los escenarios, todos los borradores con sus comprobaciones y revisores, la predicción final y los criterios del juez grabado. Se excluyeron cuentas, costes, sesiones y metadatos de administración. Los textos se conservan exactamente, incluidas las ranuras sin llaves, los nombres escritos a mano y los errores. `textsSha256` firma el JSON compacto de las matrices de textos por caso, con Unicode y barras sin escapar.

Las pruebas fijan el resultado histórico: 0 de 20 primeros borradores válidos y 12 de 20 finales del modelo. Ejecutar las guardas con esas salidas comprueba rechazo y representación; no mide lo que generaría un modelo con c9. Los casos grabados no se transforman en respuestas ideales ni cuentan como una nueva medición. La aceptación de generación futura mantiene los umbrales originales y requiere una autorización distinta.
