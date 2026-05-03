<?php

use App\Http\Controllers\Admin\AdminAdministradorController;
use App\Http\Controllers\Admin\AdminAfipFiscalController;
use App\Http\Controllers\Admin\AdminAndreaniController;
use App\Http\Controllers\Admin\AdminComprobanteController;
use App\Http\Controllers\Admin\AdminCorreoArgentinoController;
use App\Http\Controllers\Admin\AdminEnvioBultoController;
use App\Http\Controllers\Admin\AdminEnvioController;
use App\Http\Controllers\Admin\AdminEnvioEventoController;
use App\Http\Controllers\Admin\AdminImagenesProductoBaseController;
use App\Http\Controllers\Admin\AdminImagenesProductoVentaController;
use App\Http\Controllers\Admin\AdminMailController;
use App\Http\Controllers\Admin\AdminPagoController;
use App\Http\Controllers\Admin\AdminProductoBaseController;
use App\Http\Controllers\Admin\AdminProductoVentaComponenteController;
use App\Http\Controllers\Admin\AdminProductoVentaController;
use App\Http\Controllers\Admin\AdminUsuarioController;
use App\Http\Controllers\Admin\AdminUsuarioDatoFacturacionController;
use App\Http\Controllers\Admin\AdminUsuarioDomicilioController;
use App\Http\Controllers\Admin\AdminUsuarioTelefonoController;
use App\Http\Controllers\Admin\AdminVentaController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\ClienteAuthController;
use App\Http\Controllers\ClienteDatoFacturacionController;
use App\Http\Controllers\ClienteDomicilioController;
use App\Http\Controllers\ClienteTelefonoController;
use App\Http\Controllers\ClienteVentaController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\PaywayWebhookController;
use App\Http\Controllers\ProductoVentaPublicController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas Públicas (Catálogo)
|--------------------------------------------------------------------------
*/
Route::get('/productos-venta', [ProductoVentaPublicController::class, 'index']);
Route::get('/productos-venta/{id}', [ProductoVentaPublicController::class, 'show']);
Route::post('/mercado-pago/webhook', [MercadoPagoWebhookController::class, 'handle'])->name('mercadopago.webhook');
Route::post('/payway/webhook', [PaywayWebhookController::class, 'handle'])->name('payway.webhook');

/*
|--------------------------------------------------------------------------
| Rutas Clientes
|--------------------------------------------------------------------------
*/
Route::prefix('clientes')->group(function () {
    // Auth pública
    Route::post('/registro', [ClienteAuthController::class, 'registro']);
    Route::post('/login', [ClienteAuthController::class, 'login']);

    // Privadas
    Route::middleware(['auth:sanctum', 'ability:role:cliente'])->group(function () {
        Route::post('/logout', [ClienteAuthController::class, 'logout']);
        Route::get('/me', [ClienteAuthController::class, 'me']);

        // Gestión del perfil
        Route::apiResource('domicilios', ClienteDomicilioController::class);
        Route::apiResource('telefonos', ClienteTelefonoController::class);
        Route::apiResource('datos-facturacion', ClienteDatoFacturacionController::class);

        // Ventas
        Route::get('/ventas', [ClienteVentaController::class, 'index']);
        Route::get('/ventas/{id}', [ClienteVentaController::class, 'show']);
    });
});

/*
|--------------------------------------------------------------------------
| Rutas Administración
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    // Auth pública admin
    Route::post('/login', [AdminAuthController::class, 'login']);

    // Privadas Admin
    Route::middleware(['auth:sanctum', 'ability:role:admin'])->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::get('/mail/config', [AdminMailController::class, 'config']);
        Route::post('/mail/test', [AdminMailController::class, 'sendTest']);
        Route::get('/logistics/correo-argentino/config', [AdminCorreoArgentinoController::class, 'config']);
        Route::post('/logistics/correo-argentino/validate-user', [AdminCorreoArgentinoController::class, 'validateUser']);
        Route::get('/logistics/correo-argentino/agencies', [AdminCorreoArgentinoController::class, 'agencies']);
        Route::post('/logistics/correo-argentino/rates', [AdminCorreoArgentinoController::class, 'rates']);
        Route::post('/logistics/correo-argentino/shipping-import', [AdminCorreoArgentinoController::class, 'shippingImport']);
        Route::post('/logistics/correo-argentino/tracking', [AdminCorreoArgentinoController::class, 'tracking']);
        Route::get('/logistics/andreani/config', [AdminAndreaniController::class, 'config']);
        Route::post('/logistics/andreani/rates', [AdminAndreaniController::class, 'rates']);
        Route::post('/logistics/andreani/shipping-import', [AdminAndreaniController::class, 'shippingImport']);
        Route::post('/logistics/andreani/tracking', [AdminAndreaniController::class, 'tracking']);
        Route::post('/afip/fiscal-lookup', [AdminAfipFiscalController::class, 'lookup']);

        // Gestión de catálogo
        Route::apiResource('productos-base', AdminProductoBaseController::class);
        Route::apiResource('imagenes-productos-base', AdminImagenesProductoBaseController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('productos-venta', AdminProductoVentaController::class);
        Route::post('productos-venta/{id}/componentes/sync', [AdminProductoVentaController::class, 'syncComponentes']);
        Route::apiResource('imagenes-productos-venta', AdminImagenesProductoVentaController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('productos-venta-componentes', AdminProductoVentaComponenteController::class);

        // Usuarios y Transacciones
        Route::apiResource('usuarios', AdminUsuarioController::class)->except(['create', 'edit']);
        Route::get('usuarios/{usuario}/telefonos', [AdminUsuarioTelefonoController::class, 'index']);
        Route::post('usuarios/{usuario}/telefonos', [AdminUsuarioTelefonoController::class, 'store']);
        Route::put('usuarios/{usuario}/telefonos/{telefono}', [AdminUsuarioTelefonoController::class, 'update']);
        Route::patch('usuarios/{usuario}/telefonos/{telefono}', [AdminUsuarioTelefonoController::class, 'update']);
        Route::delete('usuarios/{usuario}/telefonos/{telefono}', [AdminUsuarioTelefonoController::class, 'destroy']);
        Route::get('usuarios/{usuario}/domicilios', [AdminUsuarioDomicilioController::class, 'index']);
        Route::post('usuarios/{usuario}/domicilios', [AdminUsuarioDomicilioController::class, 'store']);
        Route::put('usuarios/{usuario}/domicilios/{domicilio}', [AdminUsuarioDomicilioController::class, 'update']);
        Route::patch('usuarios/{usuario}/domicilios/{domicilio}', [AdminUsuarioDomicilioController::class, 'update']);
        Route::delete('usuarios/{usuario}/domicilios/{domicilio}', [AdminUsuarioDomicilioController::class, 'destroy']);
        Route::get('usuarios/{usuario}/datos-facturacion', [AdminUsuarioDatoFacturacionController::class, 'index']);
        Route::post('usuarios/{usuario}/datos-facturacion', [AdminUsuarioDatoFacturacionController::class, 'store']);
        Route::put('usuarios/{usuario}/datos-facturacion/{datoFacturacion}', [AdminUsuarioDatoFacturacionController::class, 'update']);
        Route::patch('usuarios/{usuario}/datos-facturacion/{datoFacturacion}', [AdminUsuarioDatoFacturacionController::class, 'update']);
        Route::delete('usuarios/{usuario}/datos-facturacion/{datoFacturacion}', [AdminUsuarioDatoFacturacionController::class, 'destroy']);
        Route::get('usuarios/{usuario}/afip/consultas-fiscales', [AdminAfipFiscalController::class, 'usuarioHistory']);
        Route::post('usuarios/{usuario}/afip/fiscal-refresh', [AdminAfipFiscalController::class, 'refreshUsuario']);
        Route::apiResource('ventas', AdminVentaController::class)->only(['index', 'show', 'store']);
        Route::patch('/ventas/{id}/estado', [AdminVentaController::class, 'actualizarEstado']);
        Route::post('/ventas/{id}/checkout-pro', [AdminVentaController::class, 'generarCheckoutPro']);
        Route::post('/ventas/{id}/checkout-pro/sync', [AdminVentaController::class, 'sincronizarCheckoutPro']);

        // Pagos y Comprobantes
        Route::apiResource('pagos', AdminPagoController::class)->only(['index', 'show', 'update']);
        Route::post('/pagos/manual', [AdminPagoController::class, 'altaManual']);
        Route::post('/pagos/{id}/checkout-pro', [AdminPagoController::class, 'generarCheckoutPro']);
        Route::post('/pagos/{id}/checkout-pro/sync', [AdminPagoController::class, 'sincronizarCheckoutPro']);
        Route::apiResource('comprobantes-facturacion', AdminComprobanteController::class)->only(['index', 'show']);
        Route::post('/ventas/{id}/factura-electronica', [AdminComprobanteController::class, 'emitirFacturaVenta']);
        Route::post('/comprobantes-facturacion/{id}/nota-credito-total', [AdminComprobanteController::class, 'emitirNotaCreditoTotal']);

        // Logística
        Route::apiResource('envios', AdminEnvioController::class)->except(['destroy']);
        Route::post('/envios/{id}/cancelar', [AdminEnvioController::class, 'cancelar']);
        Route::get('/envios/{envio}/bultos', [AdminEnvioBultoController::class, 'index']);
        Route::post('/envios/{envio}/bultos', [AdminEnvioBultoController::class, 'store']);
        Route::put('/envios/{envio}/bultos/{bulto}', [AdminEnvioBultoController::class, 'update']);
        Route::patch('/envios/{envio}/bultos/{bulto}', [AdminEnvioBultoController::class, 'update']);
        Route::delete('/envios/{envio}/bultos/{bulto}', [AdminEnvioBultoController::class, 'destroy']);
        Route::get('/envios/{envio}/eventos', [AdminEnvioEventoController::class, 'index']);
        Route::post('/envios/{envio}/eventos', [AdminEnvioEventoController::class, 'store']);
        Route::put('/envios/{envio}/eventos/{evento}', [AdminEnvioEventoController::class, 'update']);
        Route::patch('/envios/{envio}/eventos/{evento}', [AdminEnvioEventoController::class, 'update']);
        Route::delete('/envios/{envio}/eventos/{evento}', [AdminEnvioEventoController::class, 'destroy']);
        Route::get('/envios/{envio}/correo-argentino/payload', [AdminCorreoArgentinoController::class, 'suggestedPayload']);
        Route::post('/envios/{envio}/correo-argentino/cotizar', [AdminCorreoArgentinoController::class, 'quoteEnvio']);
        Route::post('/envios/{envio}/correo-argentino/registrar', [AdminCorreoArgentinoController::class, 'registerEnvio']);
        Route::post('/envios/{envio}/correo-argentino/tracking', [AdminCorreoArgentinoController::class, 'trackEnvio']);
        Route::get('/envios/{envio}/andreani/payload', [AdminAndreaniController::class, 'suggestedPayload']);
        Route::post('/envios/{envio}/andreani/cotizar', [AdminAndreaniController::class, 'quoteEnvio']);
        Route::post('/envios/{envio}/andreani/registrar', [AdminAndreaniController::class, 'registerEnvio']);
        Route::post('/envios/{envio}/andreani/tracking', [AdminAndreaniController::class, 'trackEnvio']);

        // Admins
        Route::apiResource('administradores', AdminAdministradorController::class);
    });
});
