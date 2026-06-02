/**
 * Entorno de DESARROLLO (default de `ng serve`).
 *
 * Centraliza el API base del CRM. Los servicios deben leer de aquí en vez de
 * hardcodear la URL. En producción, `environment.prod.ts` lo reemplaza vía
 * fileReplacements (angular.json → build:production).
 */
export const environment = {
  production: false,
  apiBaseUrl: 'http://127.0.0.1:8080/api',
  adminApiBaseUrl: 'http://127.0.0.1:8080/api/admin',
};
