import { CommonModule, CurrencyPipe, DatePipe } from '@angular/common';
import { Component, computed, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

type StockStatus = 'Disponible' | 'Stock bajo' | 'Agotado';
type MovementType = 'Entrada' | 'Salida';

interface InventoryProduct {
  id: number;
  sku: string;
  name: string;
  category: string;
  stock: number;
  minStock: number;
  purchasePrice: number;
  salePrice: number;
  supplier: string;
  updatedAt: string;
}

interface InventoryMovement {
  id: number;
  productId: number;
  productName: string;
  type: MovementType;
  quantity: number;
  unitPrice: number;
  note: string;
  createdAt: string;
}

interface CartItem {
  productId: number;
  quantity: number;
}

@Component({
  selector: 'module-inventory',
  standalone: true,
  imports: [CommonModule, FormsModule, CurrencyPipe, DatePipe],
  template: `
    <section class="inventory-page">
      <header class="inventory-header">
        <div>
          <h1>Inventario</h1>
          <p>Controla productos, existencias, entradas, salidas y precios de venta en COP.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="btn-secondary" (click)="openMovementPanel('Entrada')">
            <span class="material-symbols-outlined" aria-hidden="true">add_box</span>
            Registrar entrada
          </button>
          <button type="button" class="btn-secondary" (click)="openMovementPanel('Salida')">
            <span class="material-symbols-outlined" aria-hidden="true">outbox</span>
            Registrar salida
          </button>
          <button type="button" class="btn-primary" (click)="openProductPanel()">
            <span class="material-symbols-outlined" aria-hidden="true">add</span>
            Nuevo producto
          </button>
        </div>
      </header>

      <section class="kpis-grid">
        <article class="kpi-card">
          <span class="material-symbols-outlined kpi-icon primary" aria-hidden="true">inventory_2</span>
          <div>
            <p class="kpi-label">Productos</p>
            <strong>{{ products().length }}</strong>
            <span>Registrados</span>
          </div>
        </article>
        <article class="kpi-card">
          <span class="material-symbols-outlined kpi-icon success" aria-hidden="true">payments</span>
          <div>
            <p class="kpi-label">Valor inventario</p>
            <strong>{{ inventoryValue() | currency: 'COP' : 'symbol' : '1.0-0' }}</strong>
            <span>Costo estimado</span>
          </div>
        </article>
        <article class="kpi-card">
          <span class="material-symbols-outlined kpi-icon warning" aria-hidden="true">warning</span>
          <div>
            <p class="kpi-label">Stock bajo</p>
            <strong>{{ lowStockCount() }}</strong>
            <span>Por reponer</span>
          </div>
        </article>
        <article class="kpi-card">
          <span class="material-symbols-outlined kpi-icon info" aria-hidden="true">point_of_sale</span>
          <div>
            <p class="kpi-label">Ventas registradas</p>
            <strong>{{ totalSales() | currency: 'COP' : 'symbol' : '1.0-0' }}</strong>
            <span>Salidas valorizadas</span>
          </div>
        </article>
      </section>

      <section class="filters-card">
        <label class="search-field">
          <span class="material-symbols-outlined" aria-hidden="true">search</span>
          <input
            type="search"
            placeholder="Buscar producto, SKU o proveedor..."
            [ngModel]="searchTerm()"
            (ngModelChange)="searchTerm.set($event)"
          />
        </label>

        <select [ngModel]="categoryFilter()" (ngModelChange)="categoryFilter.set($event)">
          <option value="all">Todas las categorías</option>
          <option *ngFor="let category of categories()" [value]="category">{{ category }}</option>
        </select>

        <select [ngModel]="statusFilter()" (ngModelChange)="statusFilter.set($event)">
          <option value="all">Todos los estados</option>
          <option value="Disponible">Disponible</option>
          <option value="Stock bajo">Stock bajo</option>
          <option value="Agotado">Agotado</option>
        </select>
      </section>

      <section class="checkout-card">
        <header class="section-header checkout-header">
          <div>
            <h2>Punto de venta</h2>
            <p>Agrega productos al carrito y registra la venta como salida de inventario.</p>
          </div>
          <span class="checkout-pill">
            <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
            {{ cartTotalItems() }} artículos
          </span>
        </header>

        <div class="checkout-layout">
          <div class="sale-products">
            <article
              *ngFor="let product of saleProducts(); trackBy: trackProduct"
              class="sale-product"
              [class.unavailable]="product.stock <= 0"
            >
              <span
                class="product-icon sale-icon material-symbols-outlined"
                [ngClass]="categoryIconClass(product.category)"
                aria-hidden="true"
              >
                {{ productIcon(product.category) }}
              </span>
              <div class="sale-product-info">
                <div class="sale-product-title">
                  <strong>{{ product.name }}</strong>
                  <span>{{ product.category }}</span>
                </div>
                <p>
                  {{ product.salePrice | currency: 'COP' : 'symbol' : '1.0-0' }}
                  · Stock {{ product.stock }}
                </p>
              </div>
              <button
                type="button"
                class="add-cart-btn"
                (click)="addToCart(product)"
                [disabled]="product.stock <= 0"
              >
                <span class="material-symbols-outlined" aria-hidden="true">add</span>
                Agregar
              </button>
            </article>
          </div>

          <aside class="cart-panel">
            <div class="cart-title">
              <span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span>
              <strong>Carrito</strong>
            </div>

            <div *ngIf="cartItems().length === 0" class="cart-empty">
              <span class="material-symbols-outlined" aria-hidden="true">remove_shopping_cart</span>
              <p>No hay productos agregados.</p>
            </div>

            <div *ngIf="cartItems().length > 0" class="cart-list">
              <article *ngFor="let item of cartItems(); trackBy: trackCartItem" class="cart-item">
                <div>
                  <strong>{{ item.product.name }}</strong>
                  <span>{{ item.product.salePrice | currency: 'COP' : 'symbol' : '1.0-0' }}</span>
                </div>
                <div class="quantity-control">
                  <button type="button" (click)="updateCartQuantity(item.product.id, -1)">
                    <span class="material-symbols-outlined" aria-hidden="true">remove</span>
                  </button>
                  <span>{{ item.quantity }}</span>
                  <button type="button" (click)="updateCartQuantity(item.product.id, 1)">
                    <span class="material-symbols-outlined" aria-hidden="true">add</span>
                  </button>
                </div>
                <strong class="cart-line-total">
                  {{ item.product.salePrice * item.quantity | currency: 'COP' : 'symbol' : '1.0-0' }}
                </strong>
                <button
                  type="button"
                  class="remove-cart-btn"
                  (click)="removeFromCart(item.product.id)"
                  aria-label="Quitar producto"
                >
                  <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </button>
              </article>
            </div>

            <div class="cart-footer">
              <div>
                <span>Total</span>
                <strong>{{ cartTotalPrice() | currency: 'COP' : 'symbol' : '1.0-0' }}</strong>
              </div>
              <button
                type="button"
                class="btn-primary full"
                (click)="checkoutCart()"
                [disabled]="cartItems().length === 0"
              >
                <span class="material-symbols-outlined" aria-hidden="true">credit_card</span>
                Registrar venta
              </button>
            </div>
          </aside>
        </div>
      </section>

      <section class="inventory-layout">
        <article class="table-card">
          <header class="section-header">
            <div>
              <h2>Productos</h2>
              <p>{{ filteredProducts().length }} productos visibles</p>
            </div>
          </header>

          <div class="product-cards-grid" role="list">
            <article
              *ngFor="let product of filteredProducts(); trackBy: trackProduct; let last = last"
              class="product-service-card"
              [ngClass]="productCardClass(product)"
              role="listitem"
              [class.is-out]="stockStatus(product) === 'Agotado'"
            >
              <div class="product-card-content">
                <div class="product-card-top">
                  <span class="category-chip">{{ product.category }}</span>
                  <span class="status-pill" [ngClass]="statusClass(product)">
                    {{ stockStatus(product) }}
                  </span>
                </div>

                <div class="product-card-title">
                  <h3>{{ product.name }}</h3>
                  <p>{{ product.sku }} · {{ product.supplier }}</p>
                </div>

                <div class="product-card-metrics">
                  <div class="metric">
                    <span>Stock</span>
                    <strong class="stock-number" [ngClass]="stockLevelClass(product)">
                      {{ product.stock }}
                    </strong>
                  </div>
                  <div class="metric">
                    <span>Compra</span>
                    <strong>{{ product.purchasePrice | currency: 'COP' : 'symbol' : '1.0-0' }}</strong>
                  </div>
                  <div class="metric">
                    <span>Venta</span>
                    <strong>{{ product.salePrice | currency: 'COP' : 'symbol' : '1.0-0' }}</strong>
                  </div>
                </div>

                <div class="product-card-actions">
                  <button type="button" class="item-action" (click)="openMovementPanel('Entrada', product)">
                    <span class="material-symbols-outlined" aria-hidden="true">add_box</span>
                    Entrada
                  </button>
                  <button type="button" class="item-action" (click)="openMovementPanel('Salida', product)">
                    <span class="material-symbols-outlined" aria-hidden="true">outbox</span>
                    Salida
                  </button>
                  <button
                    type="button"
                    class="item-action primary"
                    (click)="addToCart(product)"
                    [disabled]="product.stock <= 0"
                  >
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                    Vender
                  </button>
                </div>
              </div>

              <span
                class="product-card-art material-symbols-outlined"
                [ngClass]="categoryIconClass(product.category)"
                aria-hidden="true"
              >
                {{ productIcon(product.category) }}
              </span>
            </article>
          </div>
        </article>

        <aside class="activity-card">
          <header class="section-header">
            <div>
              <h2>Movimientos</h2>
              <p>Entradas y salidas recientes</p>
            </div>
          </header>

          <div class="movement-list">
            <article
              *ngFor="let movement of recentMovements(); trackBy: trackMovement"
              class="movement-item"
              [class.out]="movement.type === 'Salida'"
            >
              <span class="movement-icon material-symbols-outlined" aria-hidden="true">
                {{ movement.type === 'Entrada' ? 'south_west' : 'north_east' }}
              </span>
              <div>
                <strong>{{ movement.productName }}</strong>
                <p>{{ movement.type }} · {{ movement.quantity }} unidades</p>
                <small>{{ movement.createdAt | date: 'short' }} · {{ movement.note }}</small>
              </div>
              <span class="movement-total">
                {{ movement.quantity * movement.unitPrice | currency: 'COP' : 'symbol' : '1.0-0' }}
              </span>
            </article>
          </div>
        </aside>
      </section>

      <div *ngIf="panelMode()" class="drawer-backdrop" (click)="closePanel()"></div>
      <aside *ngIf="panelMode()" class="drawer">
        <header class="drawer-header">
          <div>
            <h2>{{ panelMode() === 'product' ? 'Nuevo producto' : panelTitle() }}</h2>
            <p>{{ panelMode() === 'product' ? 'Registra producto y precio en COP.' : 'Actualiza el stock del inventario.' }}</p>
          </div>
          <button type="button" class="icon-btn" (click)="closePanel()" aria-label="Cerrar">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
          </button>
        </header>

        <form *ngIf="panelMode() === 'product'" class="drawer-form" (ngSubmit)="saveProduct()">
          <label>
            Nombre
            <input name="name" [(ngModel)]="productForm.name" required />
          </label>
          <label>
            SKU
            <input name="sku" [(ngModel)]="productForm.sku" required />
          </label>
          <label>
            Categoría
            <input name="category" [(ngModel)]="productForm.category" required />
          </label>
          <div class="form-grid">
            <label>
              Stock
              <input type="number" name="stock" [(ngModel)]="productForm.stock" min="0" required />
            </label>
            <label>
              Stock mínimo
              <input type="number" name="minStock" [(ngModel)]="productForm.minStock" min="0" required />
            </label>
          </div>
          <div class="form-grid">
            <label>
              Precio compra
              <input type="number" name="purchasePrice" [(ngModel)]="productForm.purchasePrice" min="0" required />
            </label>
            <label>
              Precio venta
              <input type="number" name="salePrice" [(ngModel)]="productForm.salePrice" min="0" required />
            </label>
          </div>
          <label>
            Proveedor
            <input name="supplier" [(ngModel)]="productForm.supplier" required />
          </label>
          <button type="submit" class="btn-primary full">Guardar producto</button>
        </form>

        <form *ngIf="panelMode() === 'movement'" class="drawer-form" (ngSubmit)="saveMovement()">
          <label>
            Producto
            <select name="productId" [(ngModel)]="movementForm.productId" required>
              <option [ngValue]="0">Selecciona producto</option>
              <option *ngFor="let product of products()" [ngValue]="product.id">
                {{ product.name }} · Stock {{ product.stock }}
              </option>
            </select>
          </label>
          <div class="form-grid">
            <label>
              Tipo
              <select name="type" [(ngModel)]="movementForm.type">
                <option value="Entrada">Entrada</option>
                <option value="Salida">Salida</option>
              </select>
            </label>
            <label>
              Cantidad
              <input type="number" name="quantity" [(ngModel)]="movementForm.quantity" min="1" required />
            </label>
          </div>
          <label>
            Precio unitario COP
            <input type="number" name="unitPrice" [(ngModel)]="movementForm.unitPrice" min="0" required />
          </label>
          <label>
            Nota
            <textarea name="note" [(ngModel)]="movementForm.note" rows="3"></textarea>
          </label>
          <button type="submit" class="btn-primary full">Guardar movimiento</button>
        </form>
      </aside>
    </section>
  `,
  styles: [
    `
      .inventory-page {
        width: 100%;
        min-width: 0;
        max-width: 1400px;
        margin: 0 auto;
        color: #0a0a0a;
      }

      .inventory-header,
      .section-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 1.5rem;
        flex-wrap: wrap;
      }

      .inventory-header {
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 2px solid #f0f0f0;
      }

      h1,
      h2,
      p {
        margin: 0;
      }

      h1 {
        font-size: 2.45rem;
        line-height: 1.1;
        font-weight: 800;
        letter-spacing: -0.02em;
      }

      .inventory-header p,
      .section-header p {
        margin-top: 0.45rem;
        color: #666;
        line-height: 1.55;
      }

      .header-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
      }

      .btn-primary,
      .btn-secondary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.55rem;
        min-height: 42px;
        padding: 0.75rem 1.15rem;
        border-radius: 10px;
        font-weight: 800;
        cursor: pointer;
        border: 1px solid transparent;
        transition: all 0.18s ease;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
        box-shadow: 0 10px 22px rgba(251, 191, 36, 0.18);
      }

      .btn-primary:hover {
        background: #f9a825;
        transform: translateY(-1px);
      }

      .btn-secondary {
        background: #ffffff;
        color: #0a0a0a;
        border-color: #e5e5e5;
      }

      .btn-secondary:hover {
        background: #f9f9f9;
        border-color: #d0d0d0;
      }

      .kpis-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
      }

      .kpi-card {
        display: grid;
        grid-template-columns: 46px minmax(0, 1fr);
        gap: 0.9rem;
        align-items: center;
        border: 1px solid #ededed;
        border-radius: 14px;
        background: #ffffff;
        padding: 1.2rem;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.04);
        min-width: 0;
      }

      .kpi-icon {
        width: 46px;
        height: 46px;
        display: grid;
        place-items: center;
        border-radius: 12px;
        background: #f7f7f7;
        font-size: 1.35rem;
      }

      .kpi-icon.primary {
        color: #fbbf24;
        background: rgba(251, 191, 36, 0.12);
      }

      .kpi-icon.success {
        color: #10b981;
        background: rgba(16, 185, 129, 0.1);
      }

      .kpi-icon.warning {
        color: #f97316;
        background: rgba(249, 115, 22, 0.1);
      }

      .kpi-icon.info {
        color: #3b82f6;
        background: rgba(59, 130, 246, 0.1);
      }

      .kpi-label {
        color: #666;
        font-size: 0.78rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .kpi-card strong {
        display: block;
        font-size: 1.45rem;
        font-weight: 900;
        line-height: 1.2;
        overflow-wrap: anywhere;
      }

      .kpi-card span:not(.material-symbols-outlined) {
        color: #999;
        font-size: 0.82rem;
      }

      .filters-card {
        display: grid;
        grid-template-columns: minmax(260px, 1fr) 220px 200px;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        padding: 1rem;
        border: 1px solid #ededed;
        border-radius: 14px;
        background: #ffffff;
      }

      .search-field {
        position: relative;
        display: block;
      }

      .search-field span {
        position: absolute;
        left: 0.85rem;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
      }

      input,
      select,
      textarea {
        width: 100%;
        min-width: 0;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #ffffff;
        color: #0a0a0a;
        padding: 0.78rem 0.9rem;
        font: inherit;
        outline: none;
      }

      .search-field input {
        padding-left: 2.55rem;
      }

      input:focus,
      select:focus,
      textarea:focus {
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.12);
      }

      .inventory-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 360px;
        gap: 1.25rem;
        align-items: start;
      }

      .table-card,
      .activity-card,
      .checkout-card {
        border: 1px solid #ededed;
        border-radius: 14px;
        background: #ffffff;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        min-width: 0;
      }

      .checkout-card {
        margin-bottom: 1.5rem;
      }

      .checkout-header {
        align-items: center;
      }

      .checkout-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.45rem 0.75rem;
        border-radius: 999px;
        background: rgba(251, 191, 36, 0.14);
        color: #92400e;
        font-size: 0.82rem;
        font-weight: 900;
      }

      .checkout-pill .material-symbols-outlined {
        font-size: 1rem;
      }

      .checkout-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 340px;
        gap: 1rem;
        padding: 1rem;
        align-items: start;
      }

      .sale-products {
        display: grid;
        gap: 0.75rem;
      }

      .sale-product {
        display: grid;
        grid-template-columns: 52px minmax(0, 1fr) auto;
        gap: 0.85rem;
        align-items: center;
        padding: 0.85rem;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        background: #ffffff;
        transition:
          border-color 0.18s ease,
          background 0.18s ease,
          transform 0.18s ease;
      }

      .sale-product:hover {
        border-color: #d0d0d0;
        background: #fcfcfc;
        transform: translateY(-1px);
      }

      .sale-product.unavailable {
        opacity: 0.58;
      }

      .sale-icon {
        width: 52px;
        height: 52px;
        font-size: 2rem;
      }

      .sale-product-info {
        min-width: 0;
      }

      .sale-product-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
      }

      .sale-product-title strong {
        overflow-wrap: anywhere;
      }

      .sale-product-title span {
        padding: 0.25rem 0.5rem;
        border-radius: 999px;
        background: #f5f5f5;
        color: #666;
        font-size: 0.72rem;
        font-weight: 800;
      }

      .sale-product-info p {
        margin: 0.25rem 0 0;
        color: #666;
        font-size: 0.86rem;
      }

      .add-cart-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        min-height: 36px;
        padding: 0.55rem 0.8rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #ffffff;
        color: #0a0a0a;
        font-weight: 850;
        cursor: pointer;
      }

      .add-cart-btn:hover:not(:disabled) {
        border-color: #fbbf24;
        background: rgba(251, 191, 36, 0.1);
      }

      .add-cart-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }

      .add-cart-btn .material-symbols-outlined {
        font-size: 1rem;
      }

      .cart-panel {
        position: sticky;
        top: 78px;
        display: flex;
        flex-direction: column;
        min-height: 360px;
        max-height: 560px;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        background: #fafafa;
        overflow: hidden;
      }

      .cart-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 1rem;
        border-bottom: 1px solid #e5e5e5;
      }

      .cart-title .material-symbols-outlined {
        color: #fbbf24;
      }

      .cart-empty {
        display: grid;
        place-items: center;
        gap: 0.5rem;
        flex: 1;
        padding: 2rem;
        color: #999;
        text-align: center;
      }

      .cart-empty .material-symbols-outlined {
        font-size: 2rem;
        opacity: 0.55;
      }

      .cart-list {
        display: grid;
        gap: 0.65rem;
        padding: 0.85rem;
        overflow-y: auto;
      }

      .cart-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto auto;
        gap: 0.55rem;
        align-items: center;
        padding: 0.7rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #ffffff;
      }

      .cart-item strong,
      .cart-item span {
        display: block;
        overflow-wrap: anywhere;
      }

      .cart-item span {
        margin-top: 0.2rem;
        color: #666;
        font-size: 0.8rem;
      }

      .quantity-control {
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        border: 1px solid #e5e5e5;
        border-radius: 999px;
        padding: 0.15rem;
        background: #fafafa;
      }

      .quantity-control button,
      .remove-cart-btn {
        width: 26px;
        height: 26px;
        display: grid;
        place-items: center;
        border: none;
        border-radius: 999px;
        background: transparent;
        color: #555;
        cursor: pointer;
      }

      .quantity-control button:hover,
      .remove-cart-btn:hover {
        background: #f0f0f0;
      }

      .quantity-control .material-symbols-outlined,
      .remove-cart-btn .material-symbols-outlined {
        font-size: 0.95rem;
      }

      .quantity-control span {
        min-width: 22px;
        margin: 0;
        text-align: center;
        color: #0a0a0a;
        font-weight: 900;
      }

      .cart-line-total {
        white-space: nowrap;
        font-size: 0.82rem;
      }

      .cart-footer {
        margin-top: auto;
        padding: 1rem;
        border-top: 1px solid #e5e5e5;
        background: #ffffff;
      }

      .cart-footer > div {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.8rem;
      }

      .cart-footer span {
        color: #666;
        font-weight: 800;
      }

      .cart-footer strong {
        font-size: 1.1rem;
        font-weight: 950;
      }

      .section-header {
        padding: 1.15rem 1.2rem;
        border-bottom: 1px solid #f0f0f0;
      }

      .section-header h2 {
        font-size: 1.05rem;
        font-weight: 900;
      }

      .product-cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
        gap: 1rem;
        padding: 1rem;
      }

      .product-service-card {
        position: relative;
        min-height: 230px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        overflow: hidden;
        padding: 1.15rem;
        border: 1px solid #ededed;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 10px 22px rgba(0, 0, 0, 0.05);
        transition:
          border-color 0.16s ease,
          box-shadow 0.16s ease,
          transform 0.16s ease;
      }

      .product-service-card:hover {
        border-color: #ededed;
        box-shadow: 0 16px 34px rgba(0, 0, 0, 0.08);
        transform: translateY(-2px);
      }

      .product-service-card.card-supplements {
        background: linear-gradient(135deg, #ffffff 0%, #f6f0ff 100%);
      }

      .product-service-card.card-drinks {
        background: linear-gradient(135deg, #ffffff 0%, #eaf7ff 100%);
      }

      .product-service-card.card-accessories {
        background: linear-gradient(135deg, #ffffff 0%, #fff7df 100%);
      }

      .product-service-card.card-apparel {
        background: linear-gradient(135deg, #ffffff 0%, #fff0f7 100%);
      }

      .product-service-card.card-equipment {
        background: linear-gradient(135deg, #ffffff 0%, #f3f4f6 100%);
      }

      .product-service-card.card-snacks {
        background: linear-gradient(135deg, #ffffff 0%, #fff1e5 100%);
      }

      .product-service-card.card-hygiene {
        background: linear-gradient(135deg, #ffffff 0%, #e9fff7 100%);
      }

      .product-service-card.card-services {
        background: linear-gradient(135deg, #ffffff 0%, #eef2ff 100%);
      }

      .product-service-card.is-out {
        background: linear-gradient(135deg, #ffffff 0%, #fff1f2 100%);
        border-color: #fecaca;
      }

      .product-card-content {
        position: relative;
        z-index: 1;
        display: flex;
        min-height: 100%;
        flex-direction: column;
        gap: 1rem;
      }

      .product-card-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.6rem;
        flex-wrap: wrap;
      }

      .category-chip {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.55rem;
        border-radius: 999px;
        background: #f5f5f5;
        color: #666;
        font-size: 0.72rem;
        font-weight: 850;
      }

      .product-card-title {
        display: grid;
        gap: 0.35rem;
        max-width: min(100%, 78%);
      }

      .product-card-title h3 {
        margin: 0;
        font-size: clamp(1.25rem, 3vw, 1.75rem);
        font-weight: 950;
        line-height: 1.08;
        letter-spacing: -0.02em;
        overflow-wrap: anywhere;
      }

      .product-card-title p {
        color: #666;
        font-size: 0.86rem;
        line-height: 1.45;
        overflow-wrap: anywhere;
      }

      .product-card-metrics {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.55rem;
        align-items: stretch;
      }

      .metric {
        display: grid;
        gap: 0.2rem;
        min-width: 82px;
        padding: 0.55rem 0.65rem;
        border: 1px solid #ededed;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.82);
        backdrop-filter: blur(8px);
      }

      .metric span {
        color: #999;
        font-size: 0.68rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .metric strong {
        font-size: 0.88rem;
        font-weight: 950;
        white-space: nowrap;
      }

      .stock-number {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: fit-content;
        min-width: 38px;
        min-height: 28px;
        padding: 0.2rem 0.55rem;
        border-radius: 8px;
        border: 1px solid #ededed;
      }

      .product-card-actions {
        margin-top: auto;
        display: flex;
        align-items: center;
        gap: 0.45rem;
        flex-wrap: wrap;
      }

      .item-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        min-height: 36px;
        padding: 0.55rem 0.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #ffffff;
        color: #0a0a0a;
        font-size: 0.82rem;
        font-weight: 850;
        cursor: pointer;
        transition:
          background 0.16s ease,
          border-color 0.16s ease;
      }

      .item-action:hover:not(:disabled) {
        border-color: #d0d0d0;
        background: #f9f9f9;
      }

      .item-action.primary {
        border-color: rgba(251, 191, 36, 0.55);
        background: rgba(251, 191, 36, 0.14);
        color: #92400e;
      }

      .item-action.primary:hover:not(:disabled) {
        border-color: #fbbf24;
        background: rgba(251, 191, 36, 0.22);
      }

      .item-action:disabled {
        opacity: 0.45;
        cursor: not-allowed;
      }

      .item-action .material-symbols-outlined {
        font-size: 1rem;
      }

      .product-card-art {
        position: absolute;
        right: -1.15rem;
        bottom: -1.35rem;
        z-index: 0;
        width: 9rem;
        height: 9rem;
        display: grid;
        place-items: center;
        border-radius: 2rem;
        font-size: 7.25rem;
        line-height: 1;
        opacity: 0.2;
        transform: rotate(-8deg);
        transition:
          opacity 0.2s ease,
          transform 0.2s ease;
      }

      .product-service-card:hover .product-card-art {
        opacity: 0.28;
        transform: rotate(-4deg) scale(1.05);
      }

      .table-wrap {
        overflow-x: auto;
      }

      .inventory-table {
        width: 100%;
        min-width: 980px;
        border-collapse: collapse;
      }

      th,
      td {
        padding: 0.95rem 1rem;
        text-align: left;
        border-bottom: 1px solid #f3f4f6;
        vertical-align: middle;
      }

      th {
        background: #fafafa;
        color: #666;
        font-size: 0.72rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.06em;
      }

      tr:hover td {
        background: #fcfcfc;
      }

      .product-cell {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 0;
      }

      .product-icon {
        width: 48px;
        height: 48px;
        display: grid;
        place-items: center;
        border-radius: 12px;
        background: #f9fafb;
        color: #525252;
        flex: 0 0 auto;
        font-size: 1.85rem;
        line-height: 1;
        text-align: center;
        font-variation-settings:
          'FILL' 0,
          'wght' 500,
          'GRAD' 0,
          'opsz' 32;
        box-shadow:
          inset 0 0 0 1px rgba(15, 23, 42, 0.06),
          0 6px 14px rgba(15, 23, 42, 0.06);
      }

      .product-icon.material-symbols-outlined {
        display: grid;
        align-items: center;
        justify-items: center;
      }

      .icon-supplements {
        color: #7c3aed;
        background: #f3e8ff;
      }

      .icon-drinks {
        color: #0284c7;
        background: #e0f2fe;
      }

      .icon-accessories {
        color: #ca8a04;
        background: #fef3c7;
      }

      .icon-apparel {
        color: #db2777;
        background: #fce7f3;
      }

      .icon-equipment {
        color: #374151;
        background: #f3f4f6;
      }

      .icon-snacks {
        color: #c2410c;
        background: #ffedd5;
      }

      .icon-hygiene {
        color: #059669;
        background: #d1fae5;
      }

      .icon-services {
        color: #4f46e5;
        background: #e0e7ff;
      }

      .product-cell strong,
      .product-cell span {
        display: block;
        overflow-wrap: anywhere;
      }

      .product-cell span,
      .muted {
        color: #666;
        font-size: 0.82rem;
      }

      .sale-price {
        font-weight: 900;
      }

      .stock-cell {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 46px;
        min-height: 36px;
        padding: 0.45rem 0.7rem;
        border-radius: 10px;
        background: #f9fafb;
        border: 1px solid #ededed;
      }

      .stock-cell strong {
        font-size: 1rem;
        line-height: 1;
      }

      .stock-ok {
        background: #f9fafb;
        border-color: #ededed;
      }

      .stock-low {
        background: #fff7ed;
        border-color: #fed7aa;
        color: #9a3412;
      }

      .stock-out {
        background: #fef2f2;
        border-color: #fecaca;
        color: #991b1b;
      }


      .status-pill {
        display: inline-flex;
        padding: 0.35rem 0.65rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        white-space: nowrap;
      }

      .status-ok {
        background: #dcfce7;
        color: #166534;
      }

      .status-low {
        background: #fef3c7;
        color: #92400e;
      }

      .status-out {
        background: #fee2e2;
        color: #991b1b;
      }

      .action-row {
        display: flex;
        gap: 0.35rem;
      }

      .icon-btn {
        width: 36px;
        height: 36px;
        display: grid;
        place-items: center;
        border: 1px solid #e5e5e5;
        border-radius: 9px;
        background: #ffffff;
        color: #555;
        cursor: pointer;
      }

      .icon-btn:hover {
        border-color: #fbbf24;
        color: #ca8a04;
      }

      .movement-list {
        display: grid;
        gap: 0.8rem;
        padding: 1rem;
      }

      .movement-item {
        display: grid;
        grid-template-columns: 38px minmax(0, 1fr) auto;
        gap: 0.75rem;
        align-items: start;
        padding: 0.85rem;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        background: #ffffff;
      }

      .movement-icon {
        width: 38px;
        height: 38px;
        display: grid;
        place-items: center;
        border-radius: 10px;
        background: #dcfce7;
        color: #166534;
      }

      .movement-item.out .movement-icon {
        background: #fef3c7;
        color: #92400e;
      }

      .movement-item strong,
      .movement-item p,
      .movement-item small {
        display: block;
        margin: 0;
        overflow-wrap: anywhere;
      }

      .movement-item p {
        color: #666;
        font-size: 0.85rem;
        margin-top: 0.2rem;
      }

      .movement-item small {
        color: #999;
        font-size: 0.75rem;
        margin-top: 0.35rem;
      }

      .movement-total {
        color: #0a0a0a;
        font-size: 0.78rem;
        font-weight: 900;
        white-space: nowrap;
      }

      .drawer-backdrop {
        position: fixed;
        inset: 0;
        z-index: 70;
        background: rgba(0, 0, 0, 0.38);
      }

      .drawer {
        position: fixed;
        inset: 0 0 0 auto;
        z-index: 80;
        width: min(100%, 460px);
        background: #ffffff;
        box-shadow: -16px 0 34px rgba(0, 0, 0, 0.18);
        display: flex;
        flex-direction: column;
      }

      .drawer-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.4rem;
        border-bottom: 1px solid #ededed;
      }

      .drawer-header h2 {
        font-size: 1.25rem;
        font-weight: 900;
      }

      .drawer-header p {
        color: #666;
        margin-top: 0.35rem;
      }

      .drawer-form {
        display: grid;
        gap: 1rem;
        padding: 1.4rem;
        overflow-y: auto;
      }

      .drawer-form label {
        display: grid;
        gap: 0.4rem;
        color: #222;
        font-size: 0.86rem;
        font-weight: 800;
      }

      .form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
      }

      .full {
        width: 100%;
        margin-top: 0.25rem;
      }

      @media (max-width: 1180px) {
        .inventory-layout,
        .checkout-layout {
          grid-template-columns: 1fr;
        }

        .cart-panel {
          position: static;
          max-height: none;
        }

        .product-cards-grid {
          grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        }
      }

      @media (max-width: 860px) {
        .inventory-header {
          align-items: flex-start;
        }

        .header-actions {
          width: 100%;
        }

        .btn-primary,
        .btn-secondary {
          flex: 1;
        }

        .filters-card {
          grid-template-columns: 1fr;
        }
      }

      @media (max-width: 560px) {
        h1 {
          font-size: 1.85rem;
        }

        .header-actions {
          flex-direction: column;
        }

        .form-grid {
          grid-template-columns: 1fr;
        }

        .movement-item {
          grid-template-columns: 34px minmax(0, 1fr);
        }

        .movement-total {
          grid-column: 2;
        }

        .product-cards-grid {
          grid-template-columns: 1fr;
          padding: 0.75rem;
        }

        .product-card-title {
          max-width: 100%;
        }

        .product-card-metrics {
          grid-template-columns: 1fr;
        }

        .metric {
          grid-template-columns: 1fr auto;
          align-items: center;
        }

        .product-card-actions {
          display: grid;
          grid-template-columns: 1fr;
        }

        .item-action {
          width: 100%;
        }

        .product-card-art {
          width: 7rem;
          height: 7rem;
          font-size: 5.5rem;
        }

        .sale-product {
          grid-template-columns: 48px minmax(0, 1fr);
        }

        .add-cart-btn {
          grid-column: 1 / -1;
          width: 100%;
        }

        .cart-item {
          grid-template-columns: minmax(0, 1fr) auto;
        }

        .cart-line-total {
          grid-column: 1;
        }
      }
    `,
  ],
})
export default class InventoryModule {
  products = signal<InventoryProduct[]>([
    {
      id: 1,
      sku: 'SUP-WHEY-2LB',
      name: 'Proteína Whey 2 lb',
      category: 'Suplementos',
      stock: 18,
      minStock: 6,
      purchasePrice: 95000,
      salePrice: 135000,
      supplier: 'NutriFit',
      updatedAt: '2026-05-01T09:20:00',
    },
    {
      id: 2,
      sku: 'ACC-GUA-L',
      name: 'Guantes de entrenamiento L',
      category: 'Accesorios',
      stock: 5,
      minStock: 8,
      purchasePrice: 28000,
      salePrice: 45000,
      supplier: 'FitGear',
      updatedAt: '2026-04-30T16:15:00',
    },
    {
      id: 3,
      sku: 'BEB-HID-500',
      name: 'Bebida hidratante 500 ml',
      category: 'Bebidas',
      stock: 42,
      minStock: 20,
      purchasePrice: 2500,
      salePrice: 5000,
      supplier: 'Distribuidora Norte',
      updatedAt: '2026-05-01T11:05:00',
    },
    {
      id: 4,
      sku: 'MER-CAM-IB',
      name: 'Camiseta Iron Body',
      category: 'Merchandising',
      stock: 0,
      minStock: 4,
      purchasePrice: 32000,
      salePrice: 65000,
      supplier: 'Textiles Pro',
      updatedAt: '2026-04-28T10:00:00',
    },
  ]);

  movements = signal<InventoryMovement[]>([
    {
      id: 1,
      productId: 3,
      productName: 'Bebida hidratante 500 ml',
      type: 'Salida',
      quantity: 8,
      unitPrice: 5000,
      note: 'Venta recepción',
      createdAt: '2026-05-01T12:30:00',
    },
    {
      id: 2,
      productId: 1,
      productName: 'Proteína Whey 2 lb',
      type: 'Entrada',
      quantity: 12,
      unitPrice: 95000,
      note: 'Compra proveedor',
      createdAt: '2026-05-01T09:20:00',
    },
    {
      id: 3,
      productId: 2,
      productName: 'Guantes de entrenamiento L',
      type: 'Salida',
      quantity: 2,
      unitPrice: 45000,
      note: 'Venta mostrador',
      createdAt: '2026-04-30T17:05:00',
    },
  ]);

  searchTerm = signal('');
  categoryFilter = signal('all');
  statusFilter = signal('all');
  panelMode = signal<'product' | 'movement' | null>(null);
  cart = signal<CartItem[]>([]);

  productForm = {
    name: '',
    sku: '',
    category: '',
    stock: 0,
    minStock: 0,
    purchasePrice: 0,
    salePrice: 0,
    supplier: '',
  };

  movementForm: {
    productId: number;
    type: MovementType;
    quantity: number;
    unitPrice: number;
    note: string;
  } = {
    productId: 0,
    type: 'Entrada',
    quantity: 1,
    unitPrice: 0,
    note: '',
  };

  categories = computed(() => {
    return Array.from(new Set(this.products().map((product) => product.category))).sort();
  });

  filteredProducts = computed(() => {
    const term = this.searchTerm().trim().toLowerCase();
    const category = this.categoryFilter();
    const status = this.statusFilter();

    return this.products().filter((product) => {
      const matchesTerm =
        !term ||
        [product.name, product.sku, product.supplier, product.category]
          .join(' ')
          .toLowerCase()
          .includes(term);
      const matchesCategory = category === 'all' || product.category === category;
      const matchesStatus = status === 'all' || this.stockStatus(product) === status;

      return matchesTerm && matchesCategory && matchesStatus;
    });
  });

  inventoryValue = computed(() => {
    return this.products().reduce((sum, product) => sum + product.stock * product.purchasePrice, 0);
  });

  lowStockCount = computed(() => {
    return this.products().filter((product) => this.stockStatus(product) !== 'Disponible').length;
  });

  totalSales = computed(() => {
    return this.movements()
      .filter((movement) => movement.type === 'Salida')
      .reduce((sum, movement) => sum + movement.quantity * movement.unitPrice, 0);
  });

  recentMovements = computed(() => {
    return [...this.movements()]
      .sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime())
      .slice(0, 8);
  });

  saleProducts = computed(() => {
    return this.products().slice().sort((a, b) => {
      if (a.stock <= 0 && b.stock > 0) return 1;
      if (a.stock > 0 && b.stock <= 0) return -1;
      return a.name.localeCompare(b.name);
    });
  });

  cartItems = computed(() => {
    return this.cart()
      .map((item) => {
        const product = this.products().find((p) => p.id === item.productId);
        if (!product) return null;
        return {
          product,
          quantity: Math.min(item.quantity, product.stock),
        };
      })
      .filter((item): item is { product: InventoryProduct; quantity: number } => !!item && item.quantity > 0);
  });

  cartTotalItems = computed(() => {
    return this.cartItems().reduce((sum, item) => sum + item.quantity, 0);
  });

  cartTotalPrice = computed(() => {
    return this.cartItems().reduce((sum, item) => sum + item.product.salePrice * item.quantity, 0);
  });

  trackProduct = (_: number, product: InventoryProduct) => product.id;
  trackMovement = (_: number, movement: InventoryMovement) => movement.id;
  trackCartItem = (_: number, item: { product: InventoryProduct; quantity: number }) => item.product.id;

  stockStatus(product: InventoryProduct): StockStatus {
    if (product.stock <= 0) return 'Agotado';
    if (product.stock <= product.minStock) return 'Stock bajo';
    return 'Disponible';
  }

  statusClass(product: InventoryProduct): string {
    const status = this.stockStatus(product);
    if (status === 'Agotado') return 'status-out';
    if (status === 'Stock bajo') return 'status-low';
    return 'status-ok';
  }

  stockLevelClass(product: InventoryProduct): string {
    const status = this.stockStatus(product);
    if (status === 'Agotado') return 'stock-out';
    if (status === 'Stock bajo') return 'stock-low';
    return 'stock-ok';
  }

  productIcon(category: string): string {
    const key = this.normalizeCategory(category);
    const iconMap: { match: string[]; icon: string }[] = [
      { match: ['suplemento', 'proteina', 'creatina', 'vitamina', 'amino'], icon: 'science' },
      { match: ['bebida', 'agua', 'hidratante', 'jugo', 'energizante'], icon: 'local_drink' },
      { match: ['accesorio', 'guante', 'cinturon', 'strap', 'venda'], icon: 'sports_mma' },
      { match: ['ropa', 'camiseta', 'camisa', 'short', 'licra', 'merchandising', 'merch'], icon: 'checkroom' },
      { match: ['equipo', 'maquina', 'mancuerna', 'barra', 'disco', 'pesas'], icon: 'fitness_center' },
      { match: ['snack', 'barra', 'proteica', 'galleta', 'comida'], icon: 'bakery_dining' },
      { match: ['higiene', 'toalla', 'gel', 'shampoo', 'desinfectante'], icon: 'clean_hands' },
      { match: ['servicio', 'valoracion', 'consulta'], icon: 'support_agent' },
    ];

    return iconMap.find((item) => item.match.some((word) => key.includes(word)))?.icon || 'inventory_2';
  }

  categoryIconClass(category: string): string {
    const key = this.normalizeCategory(category);
    if (['suplemento', 'proteina', 'creatina', 'vitamina', 'amino'].some((word) => key.includes(word))) {
      return 'icon-supplements';
    }
    if (['bebida', 'agua', 'hidratante', 'jugo', 'energizante'].some((word) => key.includes(word))) {
      return 'icon-drinks';
    }
    if (['accesorio', 'guante', 'cinturon', 'strap', 'venda'].some((word) => key.includes(word))) {
      return 'icon-accessories';
    }
    if (['ropa', 'camiseta', 'camisa', 'short', 'licra', 'merchandising', 'merch'].some((word) => key.includes(word))) {
      return 'icon-apparel';
    }
    if (['equipo', 'maquina', 'mancuerna', 'barra', 'disco', 'pesas'].some((word) => key.includes(word))) {
      return 'icon-equipment';
    }
    if (['snack', 'barra', 'proteica', 'galleta', 'comida'].some((word) => key.includes(word))) {
      return 'icon-snacks';
    }
    if (['higiene', 'toalla', 'gel', 'shampoo', 'desinfectante'].some((word) => key.includes(word))) {
      return 'icon-hygiene';
    }
    if (['servicio', 'valoracion', 'consulta'].some((word) => key.includes(word))) {
      return 'icon-services';
    }
    return '';
  }

  productCardClass(product: InventoryProduct): string {
    const key = this.normalizeCategory(product.category);
    const classes: string[] = [];

    if (['suplemento', 'proteina', 'creatina', 'vitamina', 'amino'].some((word) => key.includes(word))) {
      classes.push('card-supplements');
    } else if (['bebida', 'agua', 'hidratante', 'jugo', 'energizante'].some((word) => key.includes(word))) {
      classes.push('card-drinks');
    } else if (['accesorio', 'guante', 'cinturon', 'strap', 'venda'].some((word) => key.includes(word))) {
      classes.push('card-accessories');
    } else if (['ropa', 'camiseta', 'camisa', 'short', 'licra', 'merchandising', 'merch'].some((word) => key.includes(word))) {
      classes.push('card-apparel');
    } else if (['equipo', 'maquina', 'mancuerna', 'barra', 'disco', 'pesas'].some((word) => key.includes(word))) {
      classes.push('card-equipment');
    } else if (['snack', 'barra', 'proteica', 'galleta', 'comida'].some((word) => key.includes(word))) {
      classes.push('card-snacks');
    } else if (['higiene', 'toalla', 'gel', 'shampoo', 'desinfectante'].some((word) => key.includes(word))) {
      classes.push('card-hygiene');
    } else if (['servicio', 'valoracion', 'consulta'].some((word) => key.includes(word))) {
      classes.push('card-services');
    }

    return classes.join(' ');
  }

  private normalizeCategory(category: string): string {
    return String(category || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();
  }

  openProductPanel(): void {
    this.productForm = {
      name: '',
      sku: '',
      category: '',
      stock: 0,
      minStock: 0,
      purchasePrice: 0,
      salePrice: 0,
      supplier: '',
    };
    this.panelMode.set('product');
  }

  addToCart(product: InventoryProduct): void {
    if (product.stock <= 0) return;

    this.cart.update((cart) => {
      const existing = cart.find((item) => item.productId === product.id);
      if (!existing) return [...cart, { productId: product.id, quantity: 1 }];

      return cart.map((item) =>
        item.productId === product.id
          ? { ...item, quantity: Math.min(item.quantity + 1, product.stock) }
          : item,
      );
    });
  }

  removeFromCart(productId: number): void {
    this.cart.update((cart) => cart.filter((item) => item.productId !== productId));
  }

  updateCartQuantity(productId: number, delta: number): void {
    const product = this.products().find((item) => item.id === productId);
    if (!product) return;

    this.cart.update((cart) =>
      cart
        .map((item) => {
          if (item.productId !== productId) return item;
          const nextQuantity = item.quantity + delta;
          return {
            ...item,
            quantity: Math.max(0, Math.min(nextQuantity, product.stock)),
          };
        })
        .filter((item) => item.quantity > 0),
    );
  }

  checkoutCart(): void {
    const items = this.cartItems();
    if (!items.length) return;

    const now = new Date().toISOString();
    const newMovements: InventoryMovement[] = items.map((item, index) => ({
      id: this.nextMovementId() + index,
      productId: item.product.id,
      productName: item.product.name,
      type: 'Salida',
      quantity: item.quantity,
      unitPrice: item.product.salePrice,
      note: 'Venta punto de venta',
      createdAt: now,
    }));

    this.products.update((products) =>
      products.map((product) => {
        const sold = items.find((item) => item.product.id === product.id);
        if (!sold) return product;
        return {
          ...product,
          stock: Math.max(0, product.stock - sold.quantity),
          updatedAt: now,
        };
      }),
    );

    this.movements.update((movements) => [...newMovements, ...movements]);
    this.cart.set([]);
  }

  openMovementPanel(type: MovementType, product?: InventoryProduct): void {
    this.movementForm = {
      productId: product?.id || 0,
      type,
      quantity: 1,
      unitPrice: product ? (type === 'Entrada' ? product.purchasePrice : product.salePrice) : 0,
      note: type === 'Entrada' ? 'Entrada de inventario' : 'Venta o salida de inventario',
    };
    this.panelMode.set('movement');
  }

  panelTitle(): string {
    return this.movementForm.type === 'Entrada' ? 'Registrar entrada' : 'Registrar salida';
  }

  closePanel(): void {
    this.panelMode.set(null);
  }

  saveProduct(): void {
    const nextId = Math.max(0, ...this.products().map((product) => product.id)) + 1;
    const now = new Date().toISOString();
    const product: InventoryProduct = {
      id: nextId,
      sku: this.productForm.sku.trim() || `PROD-${nextId}`,
      name: this.productForm.name.trim() || 'Producto sin nombre',
      category: this.productForm.category.trim() || 'General',
      stock: Number(this.productForm.stock) || 0,
      minStock: Number(this.productForm.minStock) || 0,
      purchasePrice: Number(this.productForm.purchasePrice) || 0,
      salePrice: Number(this.productForm.salePrice) || 0,
      supplier: this.productForm.supplier.trim() || 'Sin proveedor',
      updatedAt: now,
    };

    this.products.update((products) => [product, ...products]);

    if (product.stock > 0) {
      this.movements.update((movements) => [
        {
          id: this.nextMovementId(),
          productId: product.id,
          productName: product.name,
          type: 'Entrada',
          quantity: product.stock,
          unitPrice: product.purchasePrice,
          note: 'Stock inicial',
          createdAt: now,
        },
        ...movements,
      ]);
    }

    this.closePanel();
  }

  saveMovement(): void {
    const product = this.products().find((item) => item.id === Number(this.movementForm.productId));
    if (!product) return;

    const quantity = Math.max(1, Number(this.movementForm.quantity) || 1);
    const type = this.movementForm.type;
    const nextStock = type === 'Entrada' ? product.stock + quantity : Math.max(0, product.stock - quantity);
    const now = new Date().toISOString();

    this.products.update((products) =>
      products.map((item) =>
        item.id === product.id
          ? {
              ...item,
              stock: nextStock,
              updatedAt: now,
            }
          : item,
      ),
    );

    this.movements.update((movements) => [
      {
        id: this.nextMovementId(),
        productId: product.id,
        productName: product.name,
        type,
        quantity,
        unitPrice: Number(this.movementForm.unitPrice) || 0,
        note: this.movementForm.note.trim() || (type === 'Entrada' ? 'Entrada' : 'Salida'),
        createdAt: now,
      },
      ...movements,
    ]);

    this.closePanel();
  }

  private nextMovementId(): number {
    return Math.max(0, ...this.movements().map((movement) => movement.id)) + 1;
  }
}
