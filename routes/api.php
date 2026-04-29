<?php

use Illuminate\Support\Facades\Route;

// Controladores
use App\Http\Controllers\ProductoVentaPublicController;
use App\Http\Controllers\ClienteAuthController;
use App\Http\Controllers\ClienteDomicilioController;
use App\Http\Controllers\ClienteTelefonoController;
use App\Http\Controllers\ClienteDatoFacturacionController;
use App\Http\Controllers\ClienteVentaController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\PaywayWebhookController;

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
        Route::get('/mail/config', [App\Http\Controllers\Admin\AdminMailController::class, 'config']);
        Route::post('/mail/test', [App\Http\Controllers\Admin\AdminMailController::class, 'sendTest']);
        Route::get('/logistics/correo-argentino/config', [App\Http\Controllers\Admin\AdminCorreoArgentinoController::class, 'config']);
        Route::post('/logistics/correo-argentino/validate-user', [App\Http\Controllers\Admin\AdminCorreoArgentinoController::class, 'validateUser']);
        Route::get('/logistics/correo-argentino/agencies', [App\Http\Controllers\Admin\AdminCorreoArgentinoController::class, 'agencies']);
        Route::post('/logistics/correo-argentino/rates', [App\Http\Controllers\Admin\AdminCorreoArgentinoController::class, 'rates']);
        Route::post('/logistics/correo-argentino/shipping-import', [App\Http\Controllers\Admin\AdminCorreoArgentinoController::class, 'shippingImport']);
        Route::post('/logistics/correo-argentino/tracking', [App\Http\Controllers\Admin\AdminCorreoArgentinoController::class, 'tracking']);
        Route::get('/logistics/andreani/config', [App\Http\Controllers\Admin\AdminAndreaniController::class, 'config']);
        Route::post('/logistics/andreani/rates', [App\Http\Controllers\Admin\AdminAndreaniController::class, 'rates']);
        Route::post('/logistics/andreani/shipping-import', [App\Http\Controllers\Admin\AdminAndreaniController::class, 'shippingImport']);
        Route::post('/logistics/andreani/tracking', [App\Http\Controllers\Admin\AdminAndreaniController::class, 'tracking']);

        // Gestión de catálogo
        Route::apiResource('productos-base', App\Http\Controllers\Admin\AdminProductoBaseController::class);
        Route::apiResource('imagenes-productos-base', App\Http\Controllers\Admin\AdminImagenesProductoBaseController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('productos-venta', App\Http\Controllers\Admin\AdminProductoVentaController::class);
        Route::post('productos-venta/{id}/componentes/sync', [App\Http\Controllers\Admin\AdminProductoVentaController::class, 'syncComponentes']);
        Route::apiResource('imagenes-productos-venta', App\Http\Controllers\Admin\AdminImagenesProductoVentaController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('productos-venta-componentes', App\Http\Controllers\Admin\AdminProductoVentaComponenteController::class);
        
        // Usuarios y Transacciones
        Route::apiResource('usuarios', App\Http\Controllers\Admin\AdminUsuarioController::class)->except(['create', 'edit']);
        Route::get('usuarios/{usuario}/telefonos', [App\Http\Controllers\Admin\AdminUsuarioTelefonoController::class, 'index']);
        Route::post('usuarios/{usuario}/telefonos', [App\Http\Controllers\Admin\AdminUsuarioTelefonoController::class, 'store']);
        Route::put('usuarios/{usuario}/telefonos/{telefono}', [App\Http\Controllers\Admin\AdminUsuarioTelefonoController::class, 'update']);
        Route::patch('usuarios/{usuario}/telefonos/{telefono}', [App\Http\Controllers\Admin\AdminUsuarioTelefonoController::class, 'update']);
        Route::delete('usuarios/{usuario}/telefonos/{telefono}', [App\Http\Controllers\Admin\AdminUsuarioTelefonoController::class, 'destroy']);
        Route::get('usuarios/{usuario}/domicilios', [App\Http\Controllers\Admin\AdminUsuarioDomicilioController::class, 'index']);
        Route::post('usuarios/{usuario}/domicilios', [App\Http\Controllers\Admin\AdminUsuarioDomicilioController::class, 'store']);
        Route::put('usuarios/{usuario}/domicilios/{domicilio}', [App\Http\Controllers\Admin\AdminUsuarioDomicilioController::class, 'update']);
        Route::patch('usuarios/{usuario}/domicilios/{domicilio}', [App\Http\Controllers\Admin\AdminUsuarioDomicilioController::class, 'update']);
        Route::delete('usuarios/{usuario}/domicilios/{domicilio}', [App\Http\Controllers\Admin\AdminUsuarioDomicilioController::class, 'destroy']);
        Route::get('usuarios/{usuario}/datos-facturacion', [App\Http\Controllers\Admin\AdminUsuarioDatoFacturacionController::class, 'index']);
        Route::post('usuarios/{usuario}/datos-facturacion', [App\Http\Controllers\Admin\AdminUsuarioDatoFacturacionController::class, 'store']);
        Route::put('usuarios/{usuario}/datos-facturacion/{datoFacturacion}', [App\Http\Controllers\Admin\AdminUsuarioDatoFacturacionController::class, 'update']);
        Route::patch('usuarios/{usuario}/datos-facturacion/{datoFacturacion}', [App\Http\Controllers\Admin\AdminUsuarioDatoFacturacionController::class, 'update']);
        Route::delete('usuarios/{usuario}/datos-facturacion/{datoFacturacion}', [App\Http\Controllers\Admin\AdminUsuarioDatoFacturacionController::class, 'destroy']);
        Route::apiResource('ventas', App\Http\Controllers\Admin\AdminVentaController::class)->only(['index', 'show', 'store']);
        Route::patch('/ventas/{id}/estado', [App\Http\Controllers\Admin\AdminVentaController::class, 'actualizarEstado']);
        Route::post('/ventas/{id}/checkout-pro', [App\Http\Controllers\Admin\AdminVentaController::class, 'generarCheckoutPro']);
        Route::post('/ventas/{id}/checkout-pro/sync', [App\Http\Controllers\Admin\AdminVentaController::class, 'sincronizarCheckoutPro']);
        
        // Pagos y Comprobantes
        Route::apiResource('pagos', App\Http\Controllers\Admin\AdminPagoController::class)->only(['index', 'show', 'update']);
        Route::post('/pagos/manual', [App\Http\Controllers\Admin\AdminPagoController::class, 'altaManual']);
        Route::post('/pagos/{id}/checkout-pro', [App\Http\Controllers\Admin\AdminPagoController::class, 'generarCheckoutPro']);
        Route::post('/pagos/{id}/checkout-pro/sync', [App\Http\Controllers\Admin\AdminPagoController::class, 'sincronizarCheckoutPro']);
        Route::apiResource('comprobantes-facturacion', App\Http\Controllers\Admin\AdminComprobanteController::class)->only(['index', 'show']);
        
        // Logística
        Route::apiResource('envios', App\Http\Controllers\Admin\AdminEnvioController::class)->except(['destroy']);
        Route::post('/envios/{id}/cancelar', [App\Http\Controllers\Admin\AdminEnvioController::class, 'cancelar']);
        Route::get('/envios/{envio}/bultos', [App\Http\Controllers\Admin\AdminEnvioBultoController::class, 'index']);
        Route::post('/envios/{envio}/bultos', [App\Http\Controllers\Admin\AdminEnvioBultoController::class, 'store']);
        Route::put('/envios/{envio}/bultos/{bulto}', [App\Http\Controllers\Admin\AdminEnvioBultoController::class, 'update']);
        Route::patch('/envios/{envio}/bultos/{bulto}', [App\Http\Controllers\Admin\AdminEnvioBultoController::class, 'update']);
        Route::delete('/envios/{envio}/bultos/{bulto}', [App\Http\Controllers\Admin\AdminEnvioBultoController::class, 'destroy']);
        Route::get('/envios/{envio}/eventos', [App\Http\Controllers\Admin\AdminEnvioEventoController::class, 'index']);
        Route::post('/envios/{envio}/eventos', [App\Http\Controllers\Admin\AdminEnvioEventoController::class, 'store']);
        Route::put('/envios/{envio}/eventos/{evento}', [App\Http\Controllers\Admin\AdminEnvioEventoController::class, 'update']);
        Route::patch('/envios/{envio}/eventos/{evento}', [App\Http\Controllers\Admin\AdminEnvioEventoController::class, 'update']);
        Route::delete('/envios/{envio}/eventos/{evento}', [App\Http\Controllers\Admin\AdminEnvioEventoController::class, 'destroy']);
        Route::get('/envios/{envio}/correo-argentino/payload', [App\Http\Controllers\Admin\AdminCorreoArgentinoController::class, 'suggestedPayload']);
        Route::post('/envios/{envio}/correo-argentino/cotizar', [App\Http\Controllers\Admin\AdminCorreoArgentinoController::class, 'quoteEnvio']);
        Route::post('/envios/{envio}/correo-argentino/registrar', [App\Http\Controllers\Admin\AdminCorreoArgentinoController::class, 'registerEnvio']);
        Route::post('/envios/{envio}/correo-argentino/tracking', [App\Http\Controllers\Admin\AdminCorreoArgentinoController::class, 'trackEnvio']);
        Route::get('/envios/{envio}/andreani/payload', [App\Http\Controllers\Admin\AdminAndreaniController::class, 'suggestedPayload']);
        Route::post('/envios/{envio}/andreani/cotizar', [App\Http\Controllers\Admin\AdminAndreaniController::class, 'quoteEnvio']);
        Route::post('/envios/{envio}/andreani/registrar', [App\Http\Controllers\Admin\AdminAndreaniController::class, 'registerEnvio']);
        Route::post('/envios/{envio}/andreani/tracking', [App\Http\Controllers\Admin\AdminAndreaniController::class, 'trackEnvio']);
        
        // Admins
        Route::apiResource('administradores', App\Http\Controllers\Admin\AdminAdministradorController::class);
    });
});
