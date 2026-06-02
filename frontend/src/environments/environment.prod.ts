/**
 * Entorno de PRODUCCIÓN. Reemplaza a `environment.ts` en `ng build`
 * (build:production → fileReplacements en angular.json).
 *
 * Ajusta el dominio real antes de desplegar. NO usar ngrok aquí.
 */
export const environment = {
  production: true,
  apiBaseUrl: 'https://api.tu-dominio.com/api',
  adminApiBaseUrl: 'https://api.tu-dominio.com/api/admin',
};
