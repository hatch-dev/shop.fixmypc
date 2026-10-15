<template>
  <div class="pd-page">
    <sticky-cart-btn
      :product-inventory="selectedInventory"
      :disabled="!statusPublic"
      :product="product"

      :display-unit-price="activeUnitPrice"
      :display-old-price="stickyOldPrice"
      :selected-voucher="selectedVoucher"
      :discounted-price="discountedPrice"

      @cart-error="hasCartError"
    />
    <div v-if="product">
      <div class="p_detail-breadcrumb">
        <div class="container-fluid">
          <breadcrumb
            class="mb-20 mb-sm-15"
            :slugs="preparedSlug"
            :page="productTitle"
          />
        </div>
      </div>
      <a
        v-if="product.store.whatsapp_btn"
        class="whatsapp-btn-wrap"
        target="_blank"
        :href="`https://wa.me/${product.store.whatsapp_number}?text=${product.store.whatsapp_default_msg}`"
      >
        <i class="icon whatsapp-icon" />
      </a>
      <div
        class="detail-menu hide-sm d-none"
        v-if="currentCategories && currentCategories.length"
      >
        <div class="container-fluid">
          <div class="mlr--15">
            <nuxt-link
              v-for="(value, i) in currentCategories"
              :title="value.title"
              :to="categoryLink(value, category)"
              :key="i"
            >
              {{ value.title }}
            </nuxt-link>
          </div>
        </div>
      </div>
      <section class="all-product-info section">
       <div class="container-fluid">
              <div>
                <div v-if="showCartSuccess" class="cart-success-bar mb-3">
                  <div class="success-left">
                    <span class="success-check-icon">
                      <svg
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                      >
                        <circle cx="12" cy="12" r="12" fill="#05B942" />
                        <path
                          d="M17.7147 7.71533L9.85756 15.5725L6.28613 12.001"
                          stroke="white"
                          stroke-width="1.5"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                      </svg>
                    </span>
                    <span>
                      "{{ lastAddedProduct }}" has been added to your cart.
                    </span>
                  </div>
                  <button class="view-cart-btn" @click="handleViewCart">
                    View Cart
                  </button>
                </div>
                <div class="product-detail">
                  <div class="detail-left pr-30 pr-sm-0">
                    <div class="flex start align-start block-md">
                      <div class="product-main">
                        <div class="detail-image-wrapper">
                          <div
                            class="detail-image-inner"
                            :class="{ 'z-2': imagePopup }"
                          >
                            <product-images
                              v-if="productImage || productImageList"
                              ref="productImagesRef"
                              :title="productTitle"
                              :product="product"
                              :main-image="productImage"
                              :images="productImageList"
                              @image-popup="imagePopup = $event"
                              @add-to-wishlist="$refs.detailRight.wishListAction()"
                            />
                          </div>

                          <div class="col-lg-12">
                            <!-- accordions under the gallery: desktop layout,
                                 as reference/index.html has them -->
                            <product-accordions
                              v-if="!isNarrowLayout"
                              :product="product"
                              :total-price="payableUnitTotal"
                              :selected-voucher="selectedVoucher"
                              @has-review="fetchedReview"
                            />
                            <div
                              v-if="bulkPricing.length"
                              class="bulk-pricing-box mt-20"
                            >
                              <div class="bulk-header">
                                <h4>{{ $t('businessPricing.title') }}</h4>
                                <span class="bulk-tag">{{ $t('businessPricing.tag') }}</span>
                              </div>
                              <div class="bulk-table">
                                <!--
                                  `applies` is the band the server used to
                                  price the quantity in the box. It used to
                                  flag the last row instead, which advertised
                                  the cheapest rate as the customer's whatever
                                  they were actually buying.
                                -->
                                <div
                                  v-for="(row, i) in bulkPricing"
                                  :key="i"
                                  class="bulk-row"
                                  :class="{ best: row.applies }"
                                >
                                  <div class="bulk-left">
                                    <div class="qty">
                                      {{ bulkRangeLabel(row) }}
                                    </div>
                                    <div class="label">{{ $t('businessPricing.units') }}</div>
                                  </div>
                                  <div class="bulk-right">
                                    € {{ calculateBulkPrice(row) }}
                                  </div>
                                </div>
                              </div>
                              <p
                                v-if="appliedBandLabel"
                                class="bulk-applied"
                              >
                                {{ $t('businessPricing.applied', { range: appliedBandLabel }) }}
                              </p>
                            </div>
                          </div>
                        </div>

                        <div class="pl-30 pl-md grow">
                          <div class="pd-head">
                          <span v-if="brand" class="pd-brandline">
                            {{ brand }}
                          </span>
                          <h1 class="f-16">
                            {{ productTitle }}
                          </h1>
                          <div class="config-values">
                            <span
                              v-for="(attr, index) in selectedInventory.inventory_attributes"
                              :key="index"
                              class="config-pill"
                            >
                              {{ getAttributeTitle(attr.attribute_value_id)
                              }}{{ index !==
                                selectedInventory.inventory_attributes.length - 1 ? ' - ' : '' }}
                            </span>
                          </div>
                          <div class="mt-10 review-and-badges">
                             <div>
                              <rating-star :rating="parseFloat(productRating)" />
                              <span class="f-10 ml-5 semi-bold color-lite">
                                {{ $t('productReview.reviews', { count: reviewCount }) }}
                              </span>
                             </div>
                             <div>
                                <div class="order-badge">
                                  <span v-if="isInStock" class="badge-success"
                                    >In Stock<template v-if="stockUnits"
                                      > · {{ stockUnits }}
                                      {{ stockUnits === 1 ? 'unit' : 'units' }}</template
                                    ></span
                                  >
                                  <span v-else class="badge-danger">Out of Stock</span>
                                  <span v-if="isBackOrder" class="badge-secondary"
                                    >Back Order</span
                                  >
                                  <span v-if="selectedInventory.sku" class="config-sku">
                                    SKU:
                                    <strong>{{ selectedInventory.sku }}</strong>
                                  </span>
                                </div>
                             </div>
                           </div>
                          <ul v-if="specChips.length" class="pd-chips">
                            <li
                              v-for="(chip, index) in specChips"
                              :key="`chip-${index}`"
                              class="pd-chip"
                            >
                              {{ chip }}
                            </li>
                          </ul>
                          </div><!-- pd-head -->
                     <div class="devider w-md-100 mtb-15">&nbsp;</div>
                      <div
                            v-if="endTime"
                            class="flex sided warning-msg ptb-10 plr-15 mb-15 wrap gap-10"
                          >
                            <h5 class="color-inherit">
                              {{ product.flash_sale_title }}
                            </h5>
                            <div class="gap-10 flex">
                              <h5 class="color-inherit">
                                {{ $t('product.endsIn') }}
                              </h5>
                              <b>
                                <div class="countdown-display">
                                  <span v-if="countdownData.days > 0"
                                    >{{ countdownData.days }}
                                    {{ $tc('countdown.day',
                                    countdownData.days) }}</span
                                  >
                                  <span
                                    v-if="countdownData.hours > 0 || countdownData.days > 0"
                                    >{{ countdownData.hours }}
                                    {{
                                    $tc('countdown.hour', countdownData.hours) }}
                                  </span>
                                  <span
                                    >{{ countdownData.minutes }}
                                    {{ $tc('countdown.minute', countdownData.minutes) }}
                                  </span>
                                  <span
                                    >{{ countdownData.seconds }}
                                    {{ $tc('countdown.second', countdownData.seconds)
                                    }}</span
                                  >
                                </div>
                              </b>
                            </div>
                          </div>
                        <div ref="attrRef"></div>
                          <div class="fmpc-detail-side-by-side-attributes">
                             <div
                              v-if="vouchers && vouchers.length"
                              class="fmpc-vouchers"
                            >
                              <h4 class="fmpc-attributes-title">
                                {{ $t('accountLayout.vouchers') }}
                              </h4>
                              <div class="pos-rel">
                                <div class="fmpc-voucher-pills">
                                  <span
                                    v-for="(value, index) in vouchers"
                                    :key="index"
                                    class="info-msg"
                                    :class="{
                                    active: selectedVoucher?.code == value.code
                                  }"
                                    @click="applyVoucher(value)"
                                  >
                                    <span
                                      v-if="selectedVoucher?.code == value.code"
                                      class="voucher-check"
                                    >
                                      ✓
                                    </span>
                                    {{
                                      selectedVoucher?.code == value.code
                                        ? 'Applied'
                                        : $t('detailRight.off', {
                                          amount: getPriceType(value)
                                        })
                                    }}
                                  </span>
                                </div>
                              </div>
                            </div>
                            <div
                              v-for="(value, index) in sortedProductAttributes"
                              :key="index"
                            >
                              <h4 class="fmpc-attributes-title">
                                {{ value.title }}
                                <span
                                  v-if="isColourAttribute(value) && selectedAttributeTitle(value)"
                                  class="fmpc-attributes-note"
                                >
                                  · {{ selectedAttributeTitle(value) }}
                                </span>
                              </h4>

                              <!-- a colour attribute reads as swatches of its
                                   own colour codes, never as text pills -->
                              <div
                                v-if="isColourAttribute(value)"
                                class="fmpc-color-atrr-list"
                              >
                                <label
                                  v-for="(av, avIndex) in value.values"
                                  :key="`sw-${avIndex}`"
                                  class="fmpc-color-atrr-item"
                                  :class="{ 'disabled-attr': isAttributeDisabled(av.attribute_value_id) }"
                                  :title="av.title"
                                >
                                  <input
                                    type="radio"
                                    :name="`${value.id}`"
                                    v-model="clickedAttributes[value.id]"
                                    :value="av.id"
                                    :disabled="isAttributeDisabled(av.attribute_value_id)"
                                    @change="selectedAttribute({ key: avIndex, value: av })"
                                  />
                                  <span
                                    class="fmpc-color-atrr-circle"
                                    :style="{ background: swatchColour(av) || '#E6E7F3' }"
                                  ></span>
                                  <span class="fmpc-color-atrr-name">
                                    {{ av.title }}
                                  </span>
                                </label>
                              </div>

                              <div v-else class="flex wrap gap-10">
                                <label
                                  v-for="(av, avIndex) in value.values"
                                  :key="`av-${avIndex}`"
                                  class="rd-container rd-attr"
                                  :class="{ 'disabled-attr': isAttributeDisabled(av.attribute_value_id) }"
                                >
                                  <input
                                    type="radio"
                                    :name="`${value.id}`"
                                    v-model="clickedAttributes[value.id]"
                                    :value="av.id"
                                    :disabled="isAttributeDisabled(av.attribute_value_id)"
                                    @change="selectedAttribute({ key: avIndex, value: av })"
                                  />
                                  <span class="rd-checkmark"></span>
                                  <span class="input-content">{{ av.title }}</span>
                                </label>
                              </div>
                            </div>
                            <!-- <a href="#" class="fmpc-grading-guide">
                              <span class="fmpc-grading-guide__icon">
                                <svg
                                  width="20"
                                  height="20"
                                  viewBox="0 0 20 20"
                                  fill="none"
                                  xmlns="http://www.w3.org/2000/svg"
                                >
                                  <path
                                    d="M17.5 7.49984V14.1665C17.5 16.6665 16.25 18.3332 13.3333 18.3332H6.66667C3.75 18.3332 2.5 16.6665 2.5 14.1665V5.83317C2.5 3.33317 3.75 1.6665 6.66667 1.6665H12.5C12.5 7.49984 12.5 7.49984 17.5 7.49984Z"
                                    stroke="#33319A"
                                    stroke-width="1.5"
                                    stroke-miterlimit="10"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                  />
                                  <path
                                    d="M12.5 1.6665V5.1665C12.5 6.44984 13.55 7.49984 14.8333 7.49984H17.5"
                                    stroke="#33319A"
                                    stroke-width="1.5"
                                    stroke-miterlimit="10"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                  />
                                  <path
                                    d="M10.7598 8.95508C10.8937 9.36682 11.277 9.64534 11.71 9.64551H14.168L12.1797 11.0898C11.8292 11.3445 11.6826 11.796 11.8164 12.208L12.5752 14.5449L10.5879 13.1006C10.2374 12.846 9.76258 12.846 9.41211 13.1006L7.4248 14.5449L8.18359 12.208C8.31738 11.796 8.17075 11.3445 7.82031 11.0898L5.83203 9.64551H8.29004C8.723 9.64534 9.10632 9.36682 9.24023 8.95508L10 6.61816L10.7598 8.95508Z"
                                    stroke="#33319A"
                                  />
                                </svg>
                              </span>
                              <span class="fmpc-grading-guide__text">
                                Our grading guide
                              </span>
                            </a> -->
                          </div>
                          <div v-if="cartError.attribute" class="align-start">
                            <h4 class="fmpc-attributes-title"></h4>
                            <div class="right">
                              <p class="error mb-10">
                                {{ cartError.attribute }}
                              </p>
                            </div>
                          </div>
                          <div
                            class="editor fmpc-detail-p-description mt-30 mt-sm-15"
                            v-dompurify-html="overview"
                          />
                          <!--
                            FREQUENTLY BOUGHT TOGETHER -- the cross-sell block.

                            Three states and deliberately no fourth: a skeleton
                            while the first request is in flight, the block
                            itself once there is something to recommend, and
                            nothing at all otherwise.

                            It used to render on `main_product` alone, so a
                            product with no live recommendation still drew the
                            whole card -- a "Frequently bought together"
                            heading over the one item the customer was already
                            looking at, with "Total for 1 item" and a button
                            that added what the buy box above it adds. An empty
                            recommendation container is worse than no
                            container, so there is no empty state here: the
                            section is simply not there.

                            The skeleton is the same card at the same size, so
                            the page does not jump when the real one arrives.
                            It is only shown for the FIRST load -- a requote
                            after a quantity or variation change leaves the
                            block on screen and moves the prices in it, because
                            replacing a block the customer is reading with
                            grey bars is the "Loading... products... Loading..."
                            flicker rule 6 rules out.

                            The card itself is NOT the cross-sell block. It
                            also carries Accessories & Upgrades and the
                            Essentials Bundle, which have their own sources and
                            must not vanish when a recommendation request
                            fails -- so it exists as soon as the product does,
                            and only its cross-sell half follows the request.

                            Hence v-show for the "all three are empty" case
                            rather than v-if: <product-upgrades> is the only
                            thing that knows whether it has any upgrades, so
                            destroying the card because it looked empty would
                            take the component that decides that with it -- and
                            mounting it only once cross-sell had answered would
                            put its own request behind one it has nothing to do
                            with.
                          -->
                          <div
                            v-if="product && product.id"
                            v-show="crossSellPending || hasCrossSells || hasBundleDeals || hasUpgradeOptions"
                            class="accessories-and-upsell-main-card"
                          >
                            <template v-if="crossSellPending">
                            <h6 class="frequently-bought-together-title" aria-hidden="true">
                              <skeleton-base width="210px" :height="16" />
                            </h6>
                            <div class="frequently-bought-together-grid">
                              <div class="frequently-bought-together-grid-image-with-plus">
                                <div class="frequently-bought-together-grid-image-with-plus-grid">
                                  <div class="f-b-t-image-plus">
                                    <skeleton-base :width="56" :height="56" :radius="8" />
                                  </div>
                                  <span class="f-b-t-plus">+</span>
                                  <div class="f-b-t-image-plus">
                                    <skeleton-base :width="56" :height="56" :radius="8" />
                                  </div>
                                </div>
                              </div>
                              <div class="frequently-bought-together-grid-text-div">
                                <div class="f-b-t-total">
                                  <skeleton-base :width="110" :height="12" :mb="8" />
                                  <skeleton-base :width="88" :height="20" />
                                </div>
                                <skeleton-base width="100%" :height="42" :radius="8" />
                              </div>
                            </div>
                            <hr />
                            <div class="accessories-upgrades-text">
                              <div
                                v-for="n in 2"
                                :key="`fbt-skeleton-${n}`"
                                class="p_dt_checkbox-list"
                              >
                                <span class="accessories-upgrades-checkbox-span">
                                  <skeleton-base width="58%" :height="13" />
                                </span>
                                <span class="accessories-upgrades-price-span">
                                  <skeleton-base :width="64" :height="13" />
                                </span>
                              </div>
                            </div>
                            </template>
                            <template v-else-if="hasCrossSells">
                            <h6 class="frequently-bought-together-title">
                              Frequently bought together
                            </h6>
                            <div class="frequently-bought-together-grid">
                              <!-- LEFT: IMAGES -->
                              <div class="frequently-bought-together-grid-image-with-plus">
                                <div class="frequently-bought-together-grid-image-with-plus-grid">
                                  <div class="f-b-t-image-plus">
                                      <img :src="getImageURL(productData.main_product.image)" class="img-fluid rounded" :alt="productData.main_product.name" @error="onBundleImgError"/>
                                   </div>
                                   <!--
                                     A "+" between each thumbnail: this is what the
                                     "image-with-plus" class names were always for, and
                                     without it the row read as unrelated pictures
                                     rather than a bundle.
                                   -->
                                   <template v-for="item in selectedCrossSell">
                                      <span class="f-b-t-plus" :key="`plus-${item.id}`" aria-hidden="true">+</span>
                                      <div class="f-b-t-image-plus" :key="`img-${item.id}`">
                                          <img :src="getImageURL(item.image)" class="img-fluid rounded" :alt="item.name" @error="onBundleImgError"/>
                                      </div>
                                  


</template>
                                </div>
                              </div>
                              <!-- RIGHT: PRICE + BUTTON -->
                              <div class="frequently-bought-together-grid-text-div">
                                <div class="f-b-t-total">
                                  <span class="f-b-t-total-label">
                                    Total for {{ bundleItemCount }} item{{ bundleItemCount === 1 ? '' : 's' }}
                                  </span>
                                  <span class="f-b-t-price-row">
                                    <strong class="f-b-t-total-value">€{{ totalPrice }}</strong>
                                    <s v-if="bundleSavings > 0" class="f-b-t-was">€{{ bundleOriginalTotal.toFixed(2) }}</s>
                                  </span>
                                  <span v-if="bundleSavings > 0" class="f-b-t-save">
                                    You save €{{ bundleSavings.toFixed(2) }}
                                  </span>
                                </div>
                                <button
                                  class="btn btn-cart add-basket-extras"
                                  :disabled="bundleItemCount < 1"
                                  @click="handleCartWithExtras"
                                >
                                  Add {{ bundleItemCount }} to basket
                                </button>
                              </div>
                            </div>
                            <hr />
                            <!-- CHECKBOX LIST -->
                            <div class="accessories-upgrades-text">
                              <!-- ITEM 1 -->
                              <div class="p_dt_checkbox-list">
                                <label class="accessories-upgrades-checkbox-span">
                                  <input type="checkbox" checked disabled />
                                  This item: {{ productData.main_product.name }}
                                  <!-- Says how many, because the price beside
                                       it is the rate for that many. -->
                                  <template v-if="bundleMainQuantity > 1">
                                    × {{ bundleMainQuantity }}
                                  </template>
                                </label>
                                <span class="accessories-upgrades-price-span">
                                  <span
                                    v-if="Number(productData.main_product.old_price) > Number(productData.main_product.price)"
                                    class="text-muted text-decoration-line-through me-2"
                                    >€{{
                                    productData.main_product.old_price }}</span
                                  >
                                  <strong
                                    >€{{ productData.main_product.price }}</strong
                                  >
                                </span>
                              </div>
                              <!-- ITEM 2 -->
                              <div
                                v-for="item in productData.cross_sell"
                                :key="item.id"
                                class="p_dt_checkbox-list"
                              >
                                <div class="f-b-t-row-main">
                                  <label class="accessories-upgrades-checkbox-span">
                                    <input
                                      type="checkbox"
                                      :value="Number(item.id)"
                                      :disabled="item.requires_option && !chosenOption(item)"
                                      v-model="selectedItems"
                                    />
                                    {{ item.name }}
                                  </label>
                                  <!--
                                    A product with a real choice to make is
                                    chosen HERE, not on its own page. Rule 14
                                    is that the choice must ARRIVE MADE, not
                                    that it has to be made somewhere else: the
                                    card already carries every buyable variant
                                    with its own price, and the add sends the
                                    chosen inventory id, which the server
                                    proves again before it charges for it.

                                    Sending the customer away to choose meant
                                    abandoning the basket they were building --
                                    the ticks, the quantity and this product
                                    itself -- to buy one accessory.

                                    The tick stays out of reach until a variant
                                    is picked, because a tick without one is
                                    refused.
                                  -->
                                  <select
                                    v-if="item.requires_option"
                                    class="f-b-t-option"
                                    :class="{'is-unset': !chosenOption(item)}"
                                    :aria-label="$t('crossSell.chooseOption', {product: item.name})"
                                    :value="crossSellChoice[Number(item.id)] || ''"
                                    @change="chooseCrossSellOption(item, $event.target.value)"
                                  >
                                    <option value="">{{ $t('crossSell.chooseOne') }}</option>
                                    <option
                                      v-for="option in item.options"
                                      :key="option.inventory_id"
                                      :value="option.inventory_id"
                                    >{{ option.label || option.sku }}</option>
                                  </select>
                                </div>
                                <span class="accessories-upgrades-price-span">
                                  <span
                                    v-if="crossSellUnit(item).hasWas"
                                    class="text-muted text-decoration-line-through me-2"
                                    >€{{ crossSellUnit(item).old }}</span
                                  >
                                  <strong
                                    ><!-- "From" only while the price can still
                                         change: the variants are not all the
                                         same price and none is chosen yet.
                                      --><span
                                      v-if="item.from && !chosenOption(item)"
                                      class="f-b-t-from"
                                      >{{ $t('crossSell.from') }} </span
                                    >€{{ crossSellUnit(item).price }}</strong
                                  >
                                </span>
                              </div>
                            </div>
                            </template>
                            <!--
                              ACCESSORIES & UPGRADES.

                              Better versions of THIS product. Choosing one
                              replaces the customer's configuration rather than
                              adding anything, so it is handled by the same
                              variant switch the variation picker above uses --
                              see applyUpgrade().
                            -->
                            <product-upgrades
                              :product-id="product && product.id"
                              :inventory-id="selectedInventory && selectedInventory.id"
                              :quantity="businessQuantity"
                              @select="applyUpgrade"
                              @content="hasUpgradeOptions = $event"
                            />

                            <div
                              class="accessories-upgrades-cards essentials-bundle-div"
                              v-if="hasBundleDeals"
                            >
                              <h5 class="accessories-upgrades-cards-service-title">
                                <span class="a-u-s-icon">
                                  <svg
                                    width="23"
                                    height="20"
                                    viewBox="0 0 23 20"
                                    fill="none"
                                    xmlns="http://www.w3.org/2000/svg"
                                  >
                                    <path
                                      d="M20.0625 3.00002H12C11.9083 3.00119 11.8182 2.97647 11.7399 2.92871C11.6616 2.88095 11.5984 2.81209 11.5575 2.73002L10.89 1.35002C10.6891 0.943197 10.3781 0.600956 9.9922 0.36228C9.60634 0.123604 9.16121 -0.00191374 8.7075 2.20573e-05H2.4375C1.79103 2.20573e-05 1.17105 0.256829 0.713927 0.713949C0.256807 1.17107 0 1.79106 0 2.43752V17.0625C0 17.709 0.256807 18.329 0.713927 18.7861C1.17105 19.2432 1.79103 19.5 2.4375 19.5H20.0625C20.709 19.5 21.329 19.2432 21.7861 18.7861C22.2432 18.329 22.5 17.709 22.5 17.0625V5.43752C22.5 4.79106 22.2432 4.17107 21.7861 3.71395C21.329 3.25683 20.709 3.00002 20.0625 3.00002ZM14.31 10.9425C14.31 11.1414 14.231 11.3322 14.0903 11.4729C13.9497 11.6135 13.7589 11.6925 13.56 11.6925H12V13.2525C12 13.4514 11.921 13.6422 11.7803 13.7829C11.6397 13.9235 11.4489 14.0025 11.25 14.0025C11.0511 14.0025 10.8603 13.9235 10.7197 13.7829C10.579 13.6422 10.5 13.4514 10.5 13.2525V11.6925H8.94C8.74109 11.6925 8.55032 11.6135 8.40967 11.4729C8.26902 11.3322 8.19 11.1414 8.19 10.9425C8.19 10.7436 8.26902 10.5528 8.40967 10.4122C8.55032 10.2715 8.74109 10.1925 8.94 10.1925H10.5V8.63252C10.5 8.43361 10.579 8.24284 10.7197 8.10219C10.8603 7.96154 11.0511 7.88252 11.25 7.88252C11.4489 7.88252 11.6397 7.96154 11.7803 8.10219C11.921 8.24284 12 8.43361 12 8.63252V10.1925H13.56C13.7589 10.1925 13.9497 10.2715 14.0903 10.4122C14.231 10.5528 14.31 10.7436 14.31 10.9425Z"
                                      fill="#05B942"
                                    />
                                  </svg>
                                </span>
                                Essentials Bundle
                              </h5>
                              <div
                                v-for="(bundle, bIndex) in productData.bundle_deal"
                                :key="bundle.id"
                              >
                                <p v-if="bundle.description">
                                  {{ bundle.description }}
                                </p>
                                 <div class="essentials-bundle-plus-img">
                                  <!--
                                    A key per branch, an alt, and the same
                                    placeholder fallback the bundle row above
                                    uses. Without @error a product whose image
                                    file is missing rendered the browser's
                                    broken-image box -- which is what the three
                                    grey squares in this block were.
                                  -->
                                  <template v-for="(item, index) in bundle.items">
                                        <img
                                          :key="`bundle-${bundle.id}-img-${item.id}`"
                                          :src="getImageURL(item.image)"
                                          :alt="item.name"
                                          class="img-fluid rounded"
                                          @error="onBundleImgError"
                                        />
                                        <span
                                          v-if="index !== bundle.items.length - 1"
                                          :key="`bundle-${bundle.id}-plus-${item.id}`"
                                          class="fw-bold"
                                          aria-hidden="true"
                                        >+</span>
                                  </template>
                                 </div>
                                <div class="accessories-upgrades-text">
                                  <div class="p_dt_checkbox-list">
                                    <label class="accessories-upgrades-checkbox-span">
                                      <input
                                        type="checkbox"
                                        :value="bundle.id"
                                        v-model="selectedBundleIds"
                                        class="me-2"
                                      />
                                      <span>
                                        {{ bundleItemsText(bundle) }}
                                      </span>
                                    </label>
                                    <div>
                                      <span
                                        v-if="bundleHasSaving(bundle)"
                                        class="text-muted text-decoration-line-through me-2"
                                        >€{{
                                        bundleSellingPrice(bundle) }}</span
                                      >
                                      <strong>€{{ bundlePrice(bundle) }}</strong>
                                    </div>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                        <!-- plr-30 grow -->
                      </div>
                      <!-- flex -->
                    </div>
                  </div>
                  <!-- product-detail -->

                  <detail-right
                    ref="detailRight"
                    :product-inventory="selectedInventory"
                    :disabled="!statusPublic"
                    :product="product"
                    :bulk-pricing="bulkPricing"
                    :quoted-unit-price="quotedUnitPrice"
                    :quoted-vat="quotedVat"
                    :selected-voucher="selectedVoucher"
                    :discounted-price="discountedPrice"
                    :voucher-discount="voucherDiscountAmount"
                    :extras-total-price="extrasAddOnTotal || null"
                    :extras-old-price="extrasAddOnOldTotal || null"
                    @cart-error="hasCartError"
                    @added-to-cart="showSuccessBanner"
                    @quantity-change="onQuantityChange"
                    @unit-price-change="liveUnitPrice = $event"
                  />
                </div>
                <!-- product-detail -->
              </div>
       </div>
      </section>

        <section class="fmpc-trust-info">
         <div class="container-fluid">
          <div class="fmpc-trust-info__grid">
            <div class="fmpc-trust-info__item">
              <div class="fmpc-trust-info__icon">
                <img
                  :src="require('~/assets/images/product-secure-stats/delivery.png')"
                  alt="Quick delivery"
                />
              </div>

              <h3 class="fmpc-trust-info__title">Quick delivery and pickups</h3>

              <p class="fmpc-trust-info__description">
                You will receive your phone within 1-3 business days
              </p>
            </div>

            <div class="fmpc-trust-info__item">
              <div class="fmpc-trust-info__icon">
                <img
                  :src="require('~/assets/images/product-secure-stats/secure.png')"
                  alt="Quick delivery"
                />
              </div>

              <h3 class="fmpc-trust-info__title">Secure Payments</h3>

              <p class="fmpc-trust-info__description">
                We only use the most trustworthy local payment providers
              </p>
            </div>

            <div class="fmpc-trust-info__item">
              <div class="fmpc-trust-info__icon">
                <img
                  :src="require('~/assets/images/product-secure-stats/returns.png')"
                  alt="Quick delivery"
                />
              </div>

              <h3 class="fmpc-trust-info__title">Warranty and returns</h3>

              <p class="fmpc-trust-info__description">
                12-month warranty and 30-day free returns
              </p>
            </div>
          </div>
          </div>
        </section>
       <div class="container-fluid">
        <!-- Features -->
        <div class="product-intro-section">
          <h2 class="intro-title">{{ product.title }} Features</h2>

          <div class="intro-description" v-dompurify-html="description" />
        </div>

         <!-- Feature Images -->
         <div
          v-if="product.feature_images &&
          product.feature_images.length"
          class="feature-images-wrapper"
        >
          <div
            v-for="(item, index) in product.feature_images"
            :key="index"
            class="feature-image-item"
          >
            <img
              :src="getImageURL(item.image)"
              :alt="product.title"
              class="feature-image"
            />
          </div>
        </div>
         <!-- Technical Specifications -->
     <div
  v-if="product.specifications && product.specifications.length"
  class="spec-technical-specifications technical-specifications mt-40"
>
  <h3 class="spec-title">Technical Specifications</h3>

  <!-- Desktop -->
  <div
    class="spec-table-wrapper"
    v-if="!isMobile()"
  >
    <table class="spec-table">
      <thead>
        <tr>
          <th>Category</th>
          <th>Specification</th>
        </tr>
      </thead>

      <tbody>
        <template
          v-for="(specGroup, groupIndex) in product.specifications"
        >
          <tr
            v-for="(item, itemIndex) in specGroup.items"
            :key="`${groupIndex}-${itemIndex}`"
          >
            <td class="spec-group">
              <span v-if="itemIndex === 0">
                {{ specGroup.group }}
              </span>
            </td>

            <td class="spec-value">
              {{ item.value }}
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>

  <!-- Mobile -->
  <div
    v-if="isMobile()"
    class="spec-mobile"
  >
    <div
      v-for="(specGroup, groupIndex) in product.specifications"
      :key="groupIndex"
      class="spec-mobile-card"
    >
      <h4>{{ specGroup.group }}</h4>

      <div
        v-for="(item, itemIndex) in specGroup.items"
        :key="itemIndex"
        class="spec-mobile-item"
      >
        {{ item.value }}
      </div>
    </div>
  </div>

</div>

        <!-- accordions after the specifications table: phone layout -->
        <div v-if="isNarrowLayout" class="pd-accordions">
          <product-accordions
            :product="product"
            :total-price="payableUnitTotal"
            :selected-voucher="selectedVoucher"
            @has-review="fetchedReview"
          />
        </div>
        </div>
         <!-- container-fluid mtb-15 -->
         <client-only>
        <div class="container-fluid suggested-container mn-h-400x">
          <lazy-area v-slot:default="{ renderArea }">
            <suggested-products v-if="renderArea" :product-id="productId" />
          </lazy-area>
        </div>
       </client-only>
    </div>
    <CartDrawer :isOpen="cartDrawerOpen" @close="cartDrawerOpen = false" />
  </div>
</template>
<script>

import { mapGetters, mapActions } from 'vuex'
import util from '~/mixin/util'
import productPriceHelper from '~/mixin/productPriceHelper'
import metaHelper from '~/mixin/metaHelper'
import productHelper from '~/mixin/productHelper'
import ProductImages from '~/components/ProductImages'
import DetailRight from '~/components/DetailRight'
import LazyArea from '~/components/LazyArea'
import moment from 'moment-timezone'
import DOMPurify from 'dompurify';
import RatingStar from "~/components/RatingStar";
import Breadcrumb from "~/components/Breadcrumb";
import global from '~/mixin/global'
import StickyCartBtn from "~/components/StickyCartBtn";
import ProductUpgrades from "~/components/ProductUpgrades";
import ProductAccordions from "~/components/ProductAccordions"

// Fallback swatch colours by value name, for colour attributes whose `color`
// field has not been filled in on the attribute screen yet. The stored code
// always wins - this only keeps the swatches usable in the meantime.
const SWATCH_COLOURS = {
  'black': '#1C1C1E',
  'white': '#F4F4F6',
  'silver': '#DFE1E4',
  'grey': '#8E8E93',
  'gray': '#8E8E93',
  'space grey': '#57575B',
  'space gray': '#57575B',
  'graphite': '#4A4A4F',
  'midnight': '#1D2B36',
  'starlight': '#F2EBDE',
  'gold': '#E3C77B',
  'rose gold': '#E7BFB3',
  'bronze': '#A97142',
  'beige': '#E8DCC8',
  'cream': '#F3E9D8',
  'brown': '#8B5E3C',
  'blue': '#2D6BE4',
  'light blue': '#A7C7E7',
  'sky blue': '#8EC5F0',
  'navy': '#1B2A4A',
  'sierra blue': '#9BB5CE',
  'red': '#E03131',
  'pink': '#F2A0C0',
  'purple': '#7B4BC9',
  'deep purple': '#55506B',
  'violet': '#7B4BC9',
  'green': '#2FA84F',
  'alpine green': '#4E5F53',
  'mint': '#9FE2C0',
  'teal': '#148F8F',
  'yellow': '#F5C518',
  'orange': '#F2711C',
  'coral': '#F58F79',
  'titanium': '#B7B2AB',
  'natural titanium': '#C8C2B8',
  'blue titanium': '#4B5A6B',
  'white titanium': '#E6E4E0',
  'black titanium': '#3A3A3C'
}

export default {
  middleware: ['common-middleware'],
  head() {
    return {
      title: this.product?.meta_title,
      meta: [
        this.generatingMeta('description', this.product?.meta_description),
        this.generatingMeta('keywords', this.product?.meta_keywords),
        this.generatingMeta('og:image', this.imageURL(this.product)),
        this.generatingMeta('og:title', this.product?.meta_title),
        this.generatingMeta('og:description', this.product?.meta_description)
      ],
      link: [
        {
          rel: 'preload',
          as: 'image',
          href: this.getThumbImageURL(this.productImage)
        },
      ],

    }
  },

  data() {
    return {
      voucherResult: null,
      productData: {
        main_product: null,
        cross_sell: [],
        // A LIST of bundles, and empty rather than null: several computeds
        // read its length before the first request has answered.
        bundle_deal: [],
        
      },
      selectedVoucher: null,
      showCartSuccess: false,
      lastAddedProduct: '',
      successTimeout: null,
      /*
       * Which Essentials Bundles are ticked, by id. An array because a
       * product can be assigned more than one and a checkbox bound to a
       * boolean cannot tell them apart -- see selectedBundles.
       */
      selectedBundleIds: [],
      selectedServices: [],
      selectedItems: [],
      /*
       * Which variant the customer picked for each cross-sell that offers
       * more than one, keyed by product id. Empty means "not chosen yet",
       * which is what keeps that row's tick out of reach.
       */
      crossSellChoice: {},
      loading: false,
      /*
       * Cross-sell request bookkeeping.
       *
       * `crossSellLoaded` separates "not asked yet" from "asked, and there is
       * nothing to show" -- without it the block cannot tell a skeleton from
       * an empty section, and it flashes the skeleton forever on a product
       * with no recommendations.
       *
       * `crossSellRequestId` is the same guard fetchBulkPricing already uses.
       * A quantity change and a variation change can both be in flight at
       * once; whichever answers last would otherwise win, and the block would
       * quote a configuration the customer has already moved off.
       */
      crossSellLoaded: false,
      crossSellRequestId: 0,
      /*
       * Whether <product-upgrades> found anything to offer. Reported by the
       * component, because it is the only thing that knows -- the card it
       * sits in has to decide whether it is worth drawing at all.
       */
      hasUpgradeOptions: false,
      cartDrawerOpen: false,
      isNarrowLayout: false,
      bulkPricing: [],
      // The server's price for the quantity on screen, null until it answers.
      quotedUnitPrice: null,
      // VAT split for the quoted price; server-decided, see fetchBandPricing.
      quotedVat: null,
      // Mirrors the quantity stepper inside DetailRight, which owns it.
      businessQuantity: 1,
      // What the price components are currently showing, published by
      // DetailRight so the sticky bar does not have to read $refs.
      liveUnitPrice: 0,
      // Discards the answer to a quantity the customer has already moved on
      // from, so a slow request cannot overwrite a newer one.
      pricingRequestId: 0,
      pricingDebounce: null,
      clickedAttributes: [],
      cartError: {
        attribute: null,
        quantity: null,
      },
      selectedInventory: {},
      currentAttributes: [],
      descriptionExpand: false,
      optionChange: false,
      productInventory: null,
      imagePopup: false,
      hasReview: true,
      reviewLoaded: true,
      activatedPage: false,
      voucherPopOver: false,
      activeTemplate: null,
      // Initialize countdown data
      countdownData: {
        days: 0,
        hours: 0,
        minutes: 0,
        seconds: 0
      },
      countdownInterval: null
    }
  },
  components: {
    StickyCartBtn,
    ProductUpgrades,
    Breadcrumb,
    RatingStar,
    ProductImages,
    LazyArea,
    DetailRight,

    /*
     * Split off the page's own chunk.
     *
     * The suggested strip already waits for a LazyArea before it renders, and
     * the basket drawer only exists once someone opens it, so neither needs to
     * be inside the bundle that has to arrive before the product does.
     */
    SuggestedProducts: () => import('~/components/SuggestedProducts'),
    CartDrawer: () => import('~/components/CartDrawer')
  },
  mixins: [util, metaHelper, productHelper, productPriceHelper, global],
  computed: {
    /*
     * How many of the featured product the bundle carries — the quantity in
     * the buy box, which is what the customer has said they want.
     */
    bundleMainQuantity() {
      return Math.max(1, parseInt(this.businessQuantity, 10) || 1)
    },
    totalOldPrice() {
      let total = 0

      if (this.productData.main_product) {
        total += Number(
          this.productData.main_product.old_price ||
          this.productData.main_product.price ||
          0
        ) * this.bundleMainQuantity
      }

      // The ticked rows at the price each was quoted at -- the chosen
      // variant's own, which is what selectedCrossSell merges in.
      this.selectedCrossSell.forEach(item => {
        total += Number(item.old_price || item.price || 0)
      })

      return total.toFixed(2)
    },
    /*
     * The ticked add-ons on their own — cross-sells, service upgrades and a
     * chosen bundle, without this product.
     *
     * `totalPrice` is the bundle block's own total and includes this product
     * at quantity one, which is why handing it to the buy box as the headline
     * price wiped out the quantity band. The buy box adds these to the unit
     * price instead.
     */
    extrasAddOnTotal() {
      const main = Number(this.productData.main_product?.price || 0)
        * this.bundleMainQuantity

      return Math.max(Number(this.totalPrice) - main, 0)
    },
    extrasAddOnOldTotal() {
      const main = this.productData.main_product

      const mainOld = Number(main?.old_price || main?.price || 0)
        * this.bundleMainQuantity

      return Math.max(Number(this.totalOldPrice) - mainOld, 0)
    },
    /*
     * The unit price every other figure on the page is derived from.
     *
     * DetailRight publishes it; before, the sticky bar and the voucher maths
     * read `$refs.detailRight?.finalUnitPrice` straight out of the template,
     * which is not reactive — so they kept whatever value happened to be
     * there when the page last re-rendered for some other reason.
     */
    /*
     * The range of the band the customer is currently buying at, for the line
     * under the rate card. Empty when no band applies, which is every quantity
     * below the first band and every account not entitled to them.
     */
    appliedBandLabel() {
      const row = (this.bulkPricing || []).find(band => band.applies)

      return row ? this.bulkRangeLabel(row) : ''
    },
    /*
     * Exactly what the buy box is showing: this product at the chosen
     * quantity plus the ticked add-ons. The payment accordion previews
     * instalments from it, so it has to be the same number the customer can
     * see above it rather than the bundle block's own total.
     */
    payableUnitTotal() {
      return Number(this.activeUnitPrice) + Number(this.extrasAddOnTotal || 0)
    },
    activeUnitPrice() {
      return Number(
        this.liveUnitPrice ||
        this.quotedUnitPrice ||
        this.product?.display_price ||
        this.product?.offered ||
        this.product?.selling ||
        0
      )
    },
    voucherDiscountAmount() {

      if (!this.selectedVoucher) {
        return '0.00'
      }
      let price = this.activeUnitPrice

      let discount = 0

      if (this.selectedVoucher.type == 2) {
        discount =
          (price * this.selectedVoucher.price) / 100
        if (
          this.selectedVoucher.capped_price &&
          discount > this.selectedVoucher.capped_price
        ) {
          discount =
            Number(this.selectedVoucher.capped_price)
        }

      } else {
        discount = Number(
          this.selectedVoucher.price || 0
        )
      }

      return Math.min(discount, price)
        .toFixed(2)
    },
    /*
     * The "was" price for the sticky add-to-cart bar.
     *
     * It used to be passed products.selling unconditionally, so a product
     * whose offer price equals its selling price — which is most of this
     * catalogue — rendered "€1,000" with "€1,000" struck through beside it.
     *
     * The server works out whether there is a genuine discount
     * (App\Models\Helper\Pricing::displayRange) and sends null when there
     * isn't; the local fallback applies the same rule for any response that
     * predates that field.
     */
    stickyOldPrice() {
      if (this.product?.display_compare_price !== undefined) {
        return Number(this.product.display_compare_price || 0)
      }

      const selling = Number(this.product?.selling || 0)
      const offered = Number(this.product?.offered || 0)

      return offered > 0 && selling > offered ? selling : 0
    },
    discountedPrice() {

      let price = this.activeUnitPrice

      if (!this.selectedVoucher) {
        return price.toFixed(2)
      }

      let discount = 0

      if (this.selectedVoucher.type == 2) {

        discount =
          (price * this.selectedVoucher.price) / 100

        if (
          this.selectedVoucher.capped_price &&
          discount > this.selectedVoucher.capped_price
        ) {
          discount =
            Number(this.selectedVoucher.capped_price)
        }

      } else {
        discount = Number(
          this.selectedVoucher.price || 0
        )
      }

      return Math.max(
        price - discount,
        0
      ).toFixed(2)
    },
    /*
     * The ticked cross-sells, in the order they are listed.
     *
     * The thumbnail row and the total used to test membership differently —
     * includes(Number(item.id)) for the images but includes(item.id) for the
     * total — so a string id from the API would show a product in the bundle
     * picture while leaving it out of the price. One helper, one comparison.
     */
    selectedCrossSell() {
      return (this.productData.cross_sell || [])
        .filter(item => this.selectedItems.includes(Number(item.id)))
        /*
         * Carrying the chosen variant, so every reader of this list -- the
         * thumbnails, the count, both totals and the add itself -- is looking
         * at the same decision. The add sends `inventory_id`; without this it
         * would send the card's null and be refused with "choose an option"
         * over a row the customer had just chosen for.
         */
        .map(item => {
          const chosen = this.chosenOption(item)

          return chosen ? {
            ...item,
            inventory_id: chosen.inventory_id,
            sku: chosen.sku,
            price: chosen.price,
            old_price: chosen.old_price
          } : item
        })
    },
    /*
     * Is there anything to recommend?
     *
     * The server has already dropped everything that is not purchasable by
     * this customer right now -- unpublished, sold out with no backorder, no
     * buyable variant, or this product itself. So an empty list here is not
     * something to render a message about; it is the answer that there is no
     * section. Rule 7.
     */
    hasCrossSells() {
      return !!this.productData.main_product
        && (this.productData.cross_sell || []).length > 0
    },
    /* Bundle deals are not cross-sell, but they share the card. */
    hasBundleDeals() {
      return (this.productData.bundle_deal || []).length > 0
    },
    /*
     * The skeleton is for the FIRST load only.
     *
     * Once the block has answered once it stays on screen through every
     * requote, so changing the quantity moves the prices in place instead of
     * collapsing the card and rebuilding it. And a load that FAILED shows
     * nothing rather than shimmering forever -- rule 15: the cross-sell going
     * wrong is not the product page going wrong.
     */
    crossSellPending() {
      return this.loading && !this.crossSellLoaded
    },
    /*
     * The ticked Essentials Bundles.
     *
     * The checkbox used to bind to a single boolean while carrying
     * `:value="bundle.id"`, and Vue ignores the value on a non-array model --
     * so with two bundles assigned, ticking either one ticked both, and the
     * page could not tell which had been chosen. An array is the binding this
     * markup was always written for.
     */
    selectedBundles() {
      return (this.productData.bundle_deal || []).filter(
        bundle => this.selectedBundleIds.includes(bundle.id)
      )
    },
    /* One of everything in every ticked bundle. */
    selectedBundleItemCount() {
      return this.selectedBundles.reduce(
        (count, bundle) => count + (bundle.items || []).length, 0
      )
    },
    bundleItemCount() {
      return (this.productData.main_product ? this.bundleMainQuantity : 0)
        + this.selectedCrossSell.length
        + this.selectedBundleItemCount
    },
    // What the bundle would cost at full price, so the saving can be shown.
    bundleOriginalTotal() {
      let total = 0

      if (this.productData.main_product) {
        const main = this.productData.main_product
        total += Number(main.old_price || main.price || 0) * this.bundleMainQuantity
      }

      this.selectedCrossSell.forEach(item => {
        total += Number(item.old_price || item.price || 0)
      })

      this.selectedBundles.forEach(bundle => {
        total += Number(bundle.old_price || bundle.price || 0)
      })

      return total
    },
    bundleSavings() {
      const saving = this.bundleOriginalTotal - Number(this.totalPrice)

      // Only worth showing if it is a real, non-trivial number.
      return saving > 0.005 ? saving : 0
    },
    totalPrice() {

      let total = 0
      if (this.productData.main_product) {
        // A unit price from the server, at the customer's own quantity.
        total += Number(this.productData.main_product.price) * this.bundleMainQuantity
      }
      this.selectedCrossSell.forEach(item => {
        total += Number(item.price)
      })

      /*
       * `bundle_deal` is a LIST of bundles. This read `.final_price` straight
       * off the array, which is undefined -- so ticking the Essentials Bundle
       * turned the whole total into NaN and the strip showed "€NaN".
       *
       * Each bundle's `price` is what the basket will charge for its items,
       * summed server-side from the same per-variant figures it prices them
       * at, so adding it here cannot drift from the till.
       */
      this.selectedBundles.forEach(bundle => {
        total += Number(bundle.price) || 0
      })

      return total.toFixed(2)
    },
    description() {
      return this.product?.description || null
    },
    sortedProductAttributes() {
      return [...this.productAttributes].sort((a, b) => {
        const aIsColor = a.title.toLowerCase() === 'colour' || a.title.toLowerCase() === 'color'
        const bIsColor = b.title.toLowerCase() === 'colour' || b.title.toLowerCase() === 'color'

        if (aIsColor) return 1
        if (bIsColor) return -1
        return 0
      })
    },
    overview() {
      return this.product?.overview || null
    },
    reviewCount() {
      return this.product?.review_count || 0
    },
    productRating() {
      return this.product?.rating || 0
    },
    productImage() {
      return this.product?.image || null
    },
    productImageList() {
      return this.product?.images || null
    },
    timeDifference() {
      const len = this.product.id.toString()?.length
      let highest = ''
      for (let i = 1; i <= len; i++) {
        highest += '9'
      }
      return ((this.product.id / highest) * 100).toFixed(2)
    },
    endTime() {
      return this.product?.end_time || null
    },
    productId() {
      return this.$route.params.id
    },
    statusPublic() {
      return parseInt(this.product?.status) === 1
    },
    category() {
      return this.product?.category
    },
    currentCategories() {
      return this.product?.current_categories
    },
    productTitle() {
      return this.product?.title || ''
    },
    preparedSlug() {
      return this.categoryData?.map(i => {
        return { title: i.title, link: this.categoryLink(i) }
      })?.reverse()
    },
    categoryData() {
      return this.product?.category_data
    },
    productSlug() {
      return this.product?.slug
    },
    bundleDeal() {
      return this.product?.bundle_deal
    },
    isInStock() {
      const inventory = this.optionChange ? this.productInventory : null

      if (!inventory) return this.product?.in_stock

      // Units on the shelf only. A backorder row (is_active == 1) at zero is
      // still buyable, but it is out of stock: the "Back Order" pill beside
      // this one says so, rather than an "In Stock" that was not true.
      return Number(inventory.quantity) > 0
    },
    // Units left on the chosen inventory, for the "In Stock · N units" pill.
    // Shown for backorder rows too: the count is what is on the shelf, even
    // though more can be ordered beyond it.
    stockUnits() {
      const inventory = this.optionChange ? this.productInventory : null

      if (!inventory) return 0

      return Math.max(0, parseInt(inventory.quantity || 0))
    },
    isBackOrder() {
      const inventory = this.optionChange ? this.productInventory : null;

      if (inventory) {
        return inventory.quantity <= 0 && inventory.is_active == 1;
      }

      return false
    },
    inStock() {
      return this.isInStock ? this.$t('detail.inStock') : this.$t('detail.outOfStock')
    },
    vouchers() {
      return this.product?.vouchers;
    },
    brand() {
      return this.product?.brand?.title || ''
    },
    // Headline specs shown as chips under the title, the way the phone
    // reference does it. One chip per spec group - the first item of a group
    // is its headline value - and only values short enough to read as a chip.
    specChips() {
      return (this.product?.specifications || [])
        .map(group => (group.items && group.items[0] ? String(group.items[0].value || '').trim() : ''))
        .filter(value => value.length && value.length <= 30)
        .slice(0, 6)
    },
    productAttributeImage() {

      const attrImg = []
      this.product.product_image_names.forEach((i, key) => {

        if (i.attributes.length) {
          attrImg[i.attributes[0]?.attribute_value_id] = { value: i, key: key }
        }
      });


      return attrImg;
    },
    productAttributes() {
      this.product?.attribute.forEach(i => {
        this.clickedAttributes[i.id] = []
      })

      return this.product?.attribute.map(i => {
        return {
          ...i,
          ...{
            values: i.values.reduce((a, item) => {
              a[`${item.attribute_id}-${item.attribute_value_id}`] = item
              return a;
            }, {})
          }
        }
      })
    },
    /*
     * What the basket already holds, so a cross-sell that is in it is never
     * offered again and the Add button can say "In Cart" instead.
     */
    cartProductIds() {
      return (this.cartProducts || []).map(row => Number(row.product_id))
    },
    ...mapGetters('cart', ['cartProducts']),
    ...mapGetters('language', ['langCode']),
    ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting']),
    ...mapGetters('detail', ['product']),
  },
  methods: {
    ...mapActions('user', ['getUserToken']),
    // The colour a swatch is painted. The code set on the attribute value
    // wins; failing that a known colour name resolves to a hex.
    swatchColour(av) {
      if (av && av.color) return av.color

      const name = String((av && av.title) || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ')

      return SWATCH_COLOURS[name] || ''
    },
    // A colour attribute renders as swatches, but only once its values give
    // something to paint - otherwise every circle would look identical, so
    // those fall back to the readable text pills.
    isColourAttribute(attribute) {
      const title = (attribute?.title || '').toLowerCase()

      if (title !== 'colour' && title !== 'color') return false

      return Object.values(attribute.values || {}).some(av => this.swatchColour(av))
    },
    // The chosen value of an attribute, for the "Colour · Black" subtitle.
    selectedAttributeTitle(attribute) {
      const valueIds = Object.values(attribute?.values || {})
        .map(av => Number(av.attribute_value_id))

      const chosen = (this.selectedInventory?.inventory_attributes || [])
        .find(ia => valueIds.includes(Number(ia.attribute_value_id)))

      return chosen ? this.getAttributeTitle(chosen.attribute_value_id) : ''
    },
    getAttributeTitle(attributeValueId) {
      for (const attribute of this.product.attribute || []) {
        const value = attribute.values.find(v =>
          Number(v.attribute_value_id) === Number(attributeValueId)
        )
        if (value) {
          return value.title
        }
      }
      return ''
    },
    applyVoucher(voucher) {
      if (
        this.selectedVoucher &&
        this.selectedVoucher.code == voucher.code
      ) {
        this.selectedVoucher = null
        return
      }
      this.selectedVoucher = voucher
    },
    showSuccessBanner(product) {
      this.lastAddedProduct = product?.title || this.productTitle
      this.showCartSuccess = true

      if (this.successTimeout) {
        clearTimeout(this.successTimeout)
      }

      this.successTimeout = setTimeout(() => {
        this.showCartSuccess = false
      }, 877878787878)
    },
    async handleViewCart() {
      try {
        const user_token = await this.getUserToken()

        await this.$store.dispatch('cart/getCartByUser', {
          lang: this.langCode,
          params: { user_token }
        })

        this.cartDrawerOpen = true
        this.showCartSuccess = false

      } catch (e) {
        console.error("Cart fetch error", e)
      }
    },
    isAttributeDisabled(attributeValueId) {
      if (!this.product?.inventory) return false;

      const temp = { ...this.currentAttributes };

      let attrKey = null;

      for (let key in this.productAttributes) {
        const attr = this.productAttributes[key];
        if (Object.values(attr.values).some(v => v.attribute_value_id == attributeValueId)) {
          attrKey = attr.id;
          break;
        }
      }

      if (!attrKey) return false;

      temp[attrKey] = { attribute_value_id: attributeValueId };

      return !this.isCombinationAvailable(temp);
    },
    isCombinationAvailable(selectedAttributes) {
      if (!this.product?.inventory) return false;

      const selectedIds = Object.values(selectedAttributes)
        .filter(Boolean)
        .map(i => parseInt(i.attribute_value_id));

      return this.product.inventory.some(inv => {
        const invIds = inv.inventory_attributes.map(i =>
          parseInt(i.attribute_value_id)
        );

        const matches = selectedIds.every(id => invIds.includes(id));

        if (!matches) return false;

        return Number(inv.quantity) > 0 || (Number(inv.quantity) <= 0 && Number(inv.is_active) === 1);
      });
    },
    /*
     * What the set contains, named -- and for a product sold in variations,
     * WHICH variation.
     *
     * A bundle stores a product and the server picks the variant (the
     * cheapest buyable one), so "Apple iPhone 15 Pro" alone was selling one
     * of thirty-six without saying which. `variant` is null for a product
     * with no variations, so an ordinary accessory reads exactly as before.
     */
    bundleItemsText(bundle) {
      return bundle.items
        .map(item => item.variant ? `${item.name} (${item.variant})` : item.name)
        .join(', ')
    },
    /*
     * What the bundle costs and what it is struck through against.
     *
     * These read `final_price` and `total_price`, which the server no longer
     * sends -- and `final_price` was the admin's percentage taken off a
     * product-level total, a figure the basket had no way of charging. Both
     * are now the server's own: `price` is the sum of what these exact
     * variants will cost this customer, `old_price` the same items at list.
     */
    bundlePrice(bundle) {
      return Number(bundle.price || 0).toFixed(2)
    },
    bundleSellingPrice(bundle) {
      return Number(bundle.old_price || bundle.price || 0).toFixed(2)
    },
    /* Only worth a struck-through figure when there is a real saving. */
    bundleHasSaving(bundle) {
      return Number(bundle.savings || 0) > 0.005
    },
    getCartExtras() {
      const data = {
        cross_sell: [],
        services: [],
        upgrades: [],
        bundles: []
      }

      /*
       * One definition of "ticked", shared with the thumbnail row and the
       * total. This used to compare `item.id` raw while the pictures and the
       * price compared `Number(item.id)`, so the two could disagree about the
       * same product.
       */
      data.cross_sell = [...this.selectedCrossSell]

      /*
       * The ticked bundles. This used to assign the whole `bundle_deal` ARRAY
       * to a single `bundle` field on a boolean tick -- and nothing ever read
       * it, which is why the Essentials Bundle checkbox changed the picture
       * and nothing else: the bundle was never added to anything.
       */
      data.bundles = [...this.selectedBundles]

      return data
    },
    /*
     * Swap a missing bundle thumbnail for the placeholder.
     *
     * Without this a product whose image file is gone renders the browser's
     * broken-image box with the alt text spilling out of the 64px tile — which
     * is exactly what "Hoco UA27" was doing in the bundle row.
     */
    /*
     * The variant chosen for a cross-sell, or null while none is.
     *
     * The choice is held by PRODUCT id and looked up in the CURRENT answer,
     * which is what makes it survive a requote: changing the quantity reprices
     * every option, and this finds the same variant at its new price rather
     * than holding the figure it was quoted at. An option that has stopped
     * being buyable is simply not found any more -- see
     * pruneCrossSellSelection, which unticks the row that was relying on it.
     */
    chosenOption(item) {
      const inventoryId = this.crossSellChoice[Number(item && item.id)]

      if (!inventoryId) {
        return null
      }

      return (item.options || []).find(
        option => Number(option.inventory_id) === Number(inventoryId)
      ) || null
    },
    /*
     * What this row costs.
     *
     * The chosen variant's own price, because that is the one the basket will
     * charge -- the card's headline is the CHEAPEST option (`from`), and
     * quoting it beside a dearer chosen colour is the "advertised 450, charged
     * 500" defect this block was written to avoid.
     */
    crossSellUnit(item) {
      const chosen = this.chosenOption(item)
      const price = Number((chosen ? chosen.price : item.price) || 0)
      const was = Number((chosen ? chosen.old_price : item.old_price) || 0)

      // A "was" that is not more than the price is not a saving, and a
      // struck-out figure identical to the one beside it reads as a bug.
      return {price, old: was, hasWas: was > price + 0.005}
    },
    /*
     * Choosing a variant ticks the row as well.
     *
     * The customer picking a colour has said they want it; making them then
     * tick a box they could not reach a moment ago reads as the control being
     * broken. Clearing the choice unticks it again, because the row can no
     * longer say WHAT it would add -- and the server refuses a line without a
     * variant, so leaving it ticked would quote a price the basket never takes.
     */
    chooseCrossSellOption(item, rawId) {
      const id = Number(item.id)
      const inventoryId = rawId ? Number(rawId) : null
      const choice = {...this.crossSellChoice}

      if (inventoryId) {
        choice[id] = inventoryId
      } else {
        delete choice[id]
      }

      // Replaced wholesale: Vue 2 does not see a key added to an object in
      // place, so the tick would stay disabled until something else redrew.
      this.crossSellChoice = choice

      const ticked = this.selectedItems.some(selected => Number(selected) === id)

      if (inventoryId && !ticked) {
        this.selectedItems = [...this.selectedItems, id]
      }

      if (!inventoryId && ticked) {
        this.selectedItems = this.selectedItems.filter(
          selected => Number(selected) !== id
        )
      }
    },
    onBundleImgError(e) {
      const fallback = this.getImageURL(this.defaultImage)

      if (e.target.src !== fallback) {
        e.target.src = fallback
      }
    },
    async handleCartWithExtras() {
      try {
        const user_token = await this.getUserToken()
        const extras = this.getCartExtras()
        /*
         * The quantity the block quoted, so the basket charges what the
         * customer was shown. `price` is ignored by the server, which reprices
         * every line from the catalogue — it is sent only because the store
         * action's payload shape expects it.
         */
        await this.cartAction({
          payload: {
            user_token,
            storeVal: {
              quantity: this.bundleMainQuantity
            },
            apiVal: {
              user_token,
              product_id: this.productData.main_product.id,
              inventory_id: this.productData.main_product.inventory_id,
              price: Number(this.productData.main_product.price),
              quantity: this.bundleMainQuantity,
            }
          },
          lang: this.langCode
        })

        for (const item of extras.upgrades) {
          await this.cartAction({
            payload: {
              user_token,
              storeVal: {
                quantity: 1
              },
              apiVal: {
                user_token,
                product_id: item.product_id,
                inventory_id: item.inventory_id,
                price: Number(item.price),
                quantity: 1
              }
            },
            lang: this.langCode
          })
        }

        /*
         * The recommendations go through the cross-sell endpoint rather than
         * the plain add, because that is the one that proves them again.
         *
         * Rule 9: between the block rendering and this click the product can
         * be unpublished, the variant can sell out and the promotion can be
         * switched off. The server re-checks every line against the same
         * availability rule that built the block, adds what is still buyable
         * and says what it refused -- per line, so one sold-out accessory does
         * not take the rest of the basket with it.
         *
         * It also sends no price. The server prices each line from the
         * catalogue exactly as it does for any other basket row, which is what
         * keeps the figure on the card and the figure at the till the same.
         */
        let refused = []

        if (extras.cross_sell.length) {
          refused = await this.addCrossSellLines(extras.cross_sell, user_token)
        }

        /*
         * The Essentials Bundles, each in one all-or-nothing call. The server
         * re-proves that the bundle is still assigned to this product and
         * still complete before it writes anything -- a bundle that has lost
         * an item since the page loaded is not the bundle that was quoted, so
         * it is refused whole rather than added short.
         */
        for (const bundle of extras.bundles) {
          const bundleRefusal = await this.addBundle(bundle, user_token)

          if (bundleRefusal) {
            refused = refused.concat([bundleRefusal])
          }
        }

        this.showSuccessBanner()

        /*
         * Rule 9 again: never silently. Anything refused is named, and the
         * block is re-read so whatever is no longer buyable is gone from the
         * page rather than sitting there ticked and quoting a price.
         */
        if (refused.length) {
          this.$store.commit(
            'common/SET_TOAST_ERROR',
            refused[0].message || this.$t('crossSell.addFailed')
          )

          await this.fetchUpsellCrossSell()
        }

      } catch (e) {
        console.error("Basket error", e)
      }
    },
    /**
     * Take one Essentials Bundle. Returns a refusal, or null when it went in.
     *
     * Sends the bundle id and the product it was offered under, and nothing
     * else -- not the items, not the price. The server works out which lines a
     * bundle means and what they cost, exactly as it did when it built the
     * block, so a page that had gone stale cannot talk it into a different set
     * of products or a different total.
     */
    async addBundle(bundle, userToken) {
      const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

      try {
        const {data} = await this.$axios.post(`${baseUrl}api/v1/cart/bundle`, {
          user_token: userToken,
          product_id: this.product?.id,
          bundle_id: Number(bundle.id)
        })

        await this.$store.dispatch('cart/getCartByUser', {
          lang: this.langCode,
          params: {user_token: userToken}
        })

        if (data?.status === 200) {
          return null
        }

        /*
         * A refusal is HTTP 200 with status 201 in the body, which axios reads
         * as success -- so it has to come out of `status`, never from the
         * request having thrown.
         */
        return {message: data?.message || this.$t('crossSell.addFailed')}

      } catch (e) {
        console.error('Bundle could not be added', e)

        return {
          message: e?.response?.data?.message || this.$t('crossSell.addFailed')
        }
      }
    },
    /**
     * Take the ticked recommendations, and report what the server would not.
     *
     * Returns the refused lines. The basket is then re-read rather than
     * patched: several lines changed at once, each with its own server-decided
     * price and VAT split, and this is not the place to guess at any of them.
     */
    async addCrossSellLines(items, userToken) {
      const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

      try {
        const {data} = await this.$axios.post(`${baseUrl}api/v1/cart/cross-sell`, {
          user_token: userToken,
          primary_product_id: this.product?.id,
          products: items.map(item => ({
            product_id: Number(item.id),
            inventory_id: item.inventory_id || undefined,
            quantity: 1
          }))
        })

        await this.$store.dispatch('cart/getCartByUser', {
          lang: this.langCode,
          params: {user_token: userToken}
        })

        /*
         * 200 with skips is the partial case -- some went in, some did not.
         * 201 is the refusal shape this API uses for "nothing went in", and
         * axios reads it as success, so it has to be read out of the body.
         */
        if (data?.status === 200) {
          return data.data?.skipped || []
        }

        return data.data?.skipped?.length
          ? data.data.skipped
          : [{message: data?.message || this.$t('crossSell.addFailed')}]

      } catch (e) {
        /*
         * The primary product is already in the basket by this point, so this
         * is reported as the accessories not going in -- never as the add
         * having failed.
         */
        console.error('Cross-sell could not be added', e)

        return [{message: e?.response?.data?.message || this.$t('crossSell.addFailed')}]
      }
    },
    fixImage(image) {
      return 'https://shop.fixmypc.ie/' + image
    },
    openCartDrawer() {
      this.cartDrawerOpen = true
    },

    // The accordions sit under the gallery on desktop and after the
    // specifications on the phone, so which slot renders them is a layout
    // question, not a scroll one - hence a media query rather than a class.
    updateLayoutBreakpoint() {
      this.isNarrowLayout = window.innerWidth <= 991
    },
    // The band price is decided by the server, which is the only side that
    // knows what checkout will charge. This used to re-derive it here from
    // product.offered, but the discount hangs off the band's own
    // wholesale_price - so whenever the two differed the page advertised a
    // price the order would not honour.
    calculateBulkPrice(row) {
      return Math.max(parseFloat(row.final_price || 0), 0).toFixed(2);
    },
    /*
     * A new quantity is a new price, so re-quote.
     *
     * The band table is already on the page, so the figure on screen moves
     * immediately; this settles it against the server, which is the side that
     * decides what the basket will charge.
     */
    onQuantityChange(quantity) {
      const qty = Math.max(1, parseInt(quantity, 10) || 1)

      if (qty === this.businessQuantity) return

      this.businessQuantity = qty

      this.requoteBusinessPrice()
    },
    // Coalesces a held-down stepper into one round trip. The bundle block is
    // repriced with the buy box, or the two drift apart on screen.
    requoteBusinessPrice() {
      if (this.pricingDebounce) {
        clearTimeout(this.pricingDebounce)
      }

      this.pricingDebounce = setTimeout(() => {
        this.fetchBulkPricing()
        this.fetchUpsellCrossSell()
      }, 250)
    },
    // A band with no upper bound is stored as max 0, which rendered as
    // "20 - 0" rather than "20+".
    bulkRangeLabel(row) {
      const max = parseInt(row.max, 10)

      return max > 0 ? `${row.min} - ${max}` : `${row.min}+`
    },
    /*
     * Ask the server what this customer pays for this product, at the quantity
     * in the box, for the variant on screen.
     *
     * The page used to fetch the bands once and do the arithmetic itself. It
     * is a round trip per quantity change, which is why `bandedUnitPrice`
     * keeps the stepper responsive in the meantime — but the figure that
     * reaches the basket is always one the server decided.
     */
    async fetchBulkPricing() {
      if (!this.product?.id) return

      const requestId = ++this.pricingRequestId

      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';

        const res = await this.$axios.get(
          `${baseUrl}api/v1/business-product/${this.product.id}`,
          {
            params: {
              quantity: this.businessQuantity,
              inventory_id: this.selectedInventory?.id || undefined
            }
          }
        );

        // A slower earlier request must not overwrite a newer answer.
        if (requestId !== this.pricingRequestId) return

        const data = res?.data?.data || {}

        this.bulkPricing = data.pricing || [];

        const quoted = parseFloat(data.unit_price)

        this.quotedUnitPrice = Number.isFinite(quoted) ? quoted : null

        /*
         * The VAT split for that exact quote, so the page can say which
         * side of VAT its headline price sits on without dividing by a
         * rate itself. Same helper the basket and the order use.
         */
        this.quotedVat = data.vat_rate
          ? {
              rate: Number(data.vat_rate),
              inclusive: !!data.vat_inclusive,
              ex: Number(data.unit_ex_vat),
              vat: Number(data.unit_vat),
              inc: Number(data.unit_inc_vat),
            }
          : null

      } catch (e) {
        if (requestId !== this.pricingRequestId) return

        /*
         * No quote means no band pricing is applied, not a price of zero:
         * the components fall back to the server's display_price, which is
         * already on the product payload.
         */
        this.bulkPricing = [];
        this.quotedUnitPrice = null;
        this.quotedVat = null;
      }
    },
    /*
     * The bundle block, priced for the configuration on screen.
     *
     * The quantity and the chosen variation both go to the server, because
     * both change what the featured product costs — and the block adds that
     * product to the basket, so quoting a different one would be quoting
     * something the customer is not buying.
     */
    async fetchUpsellCrossSell() {
      if (!this.product?.id) return

      /*
       * Whichever request went out LAST describes what is on screen. A
       * quantity change and a variation change can be in flight together, and
       * without this the slower one wins by answering second -- quoting a
       * configuration the customer has already moved off. Same guard, and the
       * same reason, as fetchBulkPricing.
       */
      const requestId = ++this.crossSellRequestId

      this.loading = true

      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const res = await this.$axios.get(
          `${baseUrl}api/v1/product/upsell-crosssell/${this.product.id}`,
          {
            params: {
              quantity: this.businessQuantity,
              inventory_id: this.selectedInventory?.id || undefined
            }
          }
        );

        if (requestId !== this.crossSellRequestId) return

        /*
         * Everything here is already filtered: the server drops anything this
         * customer cannot buy right now -- unpublished, no purchasable
         * variant, or the product itself -- so the page renders what it is
         * given and never has to decide whether a card is worth showing.
         */
        this.productData = {
          main_product: res.data.main_product || null,
          cross_sell: res.data.cross_sell || [],
          bundle_deal: res.data.bundle_deal || []
        }

        this.pruneCrossSellSelection()

        this.crossSellLoaded = true

      } catch (e) {
        if (requestId !== this.crossSellRequestId) return

        /*
         * Rule 15: the recommendations failing is not the product page
         * failing. The block is left out, nothing is said about it, and
         * everything else on the page carries on.
         *
         * The prices are dropped with it rather than left on screen. They
         * were quoted for a quantity and a variation the customer has since
         * changed -- which is exactly the figure that must never survive a
         * requote, because the basket would charge the other one.
         *
         * This used to clear `bulkPricing` instead, which belongs to a
         * different request altogether: a cross-sell timeout took the
         * quantity-band table off the page with it.
         */
        console.error('Cross-sell could not be loaded', e)

        this.productData = {main_product: null, cross_sell: [], bundle_deal: []}
        this.selectedItems = []
        this.crossSellChoice = {}
        this.selectedBundleIds = []
        this.crossSellLoaded = true

      } finally {
        if (requestId === this.crossSellRequestId) {
          this.loading = false
        }
      }
    },
    /*
     * Forget ticks for anything that is no longer on offer.
     *
     * The ticks used to be cleared outright on every answer, so changing the
     * quantity -- which requotes -- silently unticked every accessory the
     * customer had chosen and dropped the total back to the product alone.
     *
     * Keeping them is also what makes rule 5 work on this block: a
     * recommendation that sold out while the page was open is gone from the
     * next answer, so its tick goes with it and the total stops including
     * something that can no longer be bought -- rather than the customer
     * finding out at the till.
     */
    pruneCrossSellSelection() {
      const offered = new Map(
        (this.productData.cross_sell || []).map(item => [Number(item.id), item])
      )

      /*
       * A ticked row has to still be on offer, and -- for a product the
       * customer had to choose a variant for -- the variant they chose has to
       * still be among the buyable ones. The answer drops an option the moment
       * it stops being purchasable, so this is how a colour that sold out
       * while the page was open loses its tick instead of being carried to the
       * till.
       */
      const stillBuyable = (item, inventoryId) => {
        if (!item.requires_option) {
          return true
        }

        return !!inventoryId && (item.options || []).some(
          option => Number(option.inventory_id) === Number(inventoryId)
        )
      }

      this.selectedItems = this.selectedItems.filter(id => {
        const item = offered.get(Number(id))

        return !!item && stillBuyable(item, this.crossSellChoice[Number(id)])
      })

      // And the choices themselves, so a product that comes back with a
      // different set of variants is not remembered as having one it no
      // longer offers.
      const choice = {}

      Object.keys(this.crossSellChoice).forEach(key => {
        const item = offered.get(Number(key))

        if (item && stillBuyable(item, this.crossSellChoice[key])) {
          choice[Number(key)] = this.crossSellChoice[key]
        }
      })

      this.crossSellChoice = choice

      /*
       * The same for the Essentials Bundles. A bundle is dropped from the
       * answer the moment any one of its items stops being buyable, so a
       * bundle that has gone incomplete since the page loaded loses its tick
       * here -- and the total stops including a price the basket would
       * refuse, rather than the customer finding out on clicking Add.
       */
      const bundles = (this.productData.bundle_deal || []).map(b => Number(b.id))

      this.selectedBundleIds = this.selectedBundleIds.filter(
        id => bundles.includes(Number(id))
      )
    },
    startCountdown() {
      if (!this.endTime) return;

      // Clear any existing interval
      if (this.countdownInterval) {
        clearInterval(this.countdownInterval);
      }

      // Calculate time difference and update countdown
      const updateCountdown = () => {
        const now = moment().tz(this.product.time_zone || 'UTC');
        const end = moment(this.endTime).tz(this.product.time_zone || 'UTC');
        const diff = end.diff(now);

        if (diff <= 0) {
          // Countdown has ended
          this.countdownData = {
            days: 0,
            hours: 0,
            minutes: 0,
            seconds: 0
          };
          clearInterval(this.countdownInterval);
          return;
        }

        // Calculate time components
        const duration = moment.duration(diff);
        this.countdownData = {
          days: Math.floor(duration.asDays()),
          hours: duration.hours(),
          minutes: duration.minutes(),
          seconds: duration.seconds()
        };
      };

      // Initial update
      updateCountdown();

      // Set up interval to update every second
      this.countdownInterval = setInterval(updateCountdown, 1000);
    },
    toggleTemplate(index) {
      this.activeTemplate = this.activeTemplate === index ? null : index;
    },
    hasCartError(event) {
      this.cartError = event
      this.$refs.attrRef.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
    },

    /**
     * Repaint the gallery for whatever is currently selected.
     *
     * Lifted out of selectedAttribute so an upgrade switches the images the
     * same way a swatch click does. The match is a best-effort score rather
     * than an exact key, because a product image is usually tagged with one
     * attribute (the colour) and not the whole combination.
     */
    syncImageToSelection() {
      if (!this.product?.product_image_names) return

      const imageMap = []

      this.product.product_image_names.map(i => {
        imageMap[i.attributes.map(j => {
          return j.attribute_value_id
        }).sort().join('-')] = i.image
      })

      const currentSelected = Object.values(this.currentAttributes)
        .map(i => i?.attribute_value_id)
        .filter(i => i)

      let bestMatch = null;
      let highestScore = -1;

      Object.keys(imageMap).forEach(key => {
        const splitKey = key.split('-').map(Number);
        const score = splitKey.reduce((acc, value) => acc + (currentSelected.includes(value) ? 1 : 0), 0);

        if (score > highestScore) {
          highestScore = score;
          bestMatch = imageMap[key];
        }
      });

      const selectedImage = highestScore > 0 ? bestMatch : null

      const imageIndex = this.productImageList?.findIndex(i => { return i.image === selectedImage })

      this.$refs.productImagesRef?.zoomActiveChange(imageIndex > -1 ? imageIndex + 1 : 0)
    },

    /**
     * Take an upgrade.
     *
     * The customer is CHANGING their configuration, not adding to it, so this
     * switches the page onto the target variant and nothing else -- no second
     * product, no extra line, no price set here.
     *
     * Everything downstream then happens through the machinery the variation
     * picker already drives: the gallery, the SKU, the stock, the quantity cap
     * and the server re-quote all follow `selectedInventory`. The server is
     * asked again for the price (see the selectedInventory.id watcher), so the
     * page never adds a difference to a price of its own.
     *
     * When the product is already in the basket the change is pushed there in
     * the same motion, because leaving the basket on the old variant is the
     * one outcome an upgrade must never produce.
     */
    async applyUpgrade(payload) {
      const inventoryId = Number(payload?.inventory_id)

      if (!inventoryId || inventoryId === Number(this.selectedInventory?.id)) {
        return
      }

      if (!this.switchToVariant(inventoryId)) {
        return
      }

      const line = (this.cartProducts || []).find(
        row => Number(row.product_id) === Number(this.product.id)
      )

      if (!line) {
        return
      }

      /*
       * Already in the basket, so the basket moves too. The server re-proves
       * the whole thing -- that the target belongs to this product, is still
       * offered as an upgrade of what the line holds, and still has the stock
       * -- and merges rather than inserting if the target is already there.
       */
      try {
        await this.cartSwitchVariant({
          payload: {
            product_id: this.product.id,
            cart_id: line.id,
            inventory_id: inventoryId
          },
          lang: this.langCode
        })
      } catch (e) {
        /*
         * The page has already switched, which is correct: the customer's
         * CHOICE is valid even when the basket refused. The toast from the
         * store says what happened to the basket line.
         */
      }
    },

    /**
     * Point the page at a variant of this product.
     *
     * Rebuilds the attribute selection from the target's own values, so the
     * variation picker above shows the new choice rather than being left
     * behind -- which is what makes the back button, a refresh and a swatch
     * click all agree with the upgrade section.
     *
     * Returns false when the variant is not one of this product's.
     */
    switchToVariant(inventoryId) {
      const target = (this.product?.inventory || []).find(
        row => Number(row.id) === Number(inventoryId)
      )

      if (!target) return false

      const attrMap = {}

      ;(target.inventory_attributes || []).forEach(attr => {
        const attribute = (this.product.attribute || []).find(a =>
          (a.values || []).some(v =>
            Number(v.attribute_value_id) === Number(attr.attribute_value_id))
        )

        if (!attribute) return

        const value = attribute.values.find(v =>
          Number(v.attribute_value_id) === Number(attr.attribute_value_id)
        )

        if (!value) return

        attrMap[value.attribute_id] = {...value, attribute_value_id: value.attribute_value_id}
        this.clickedAttributes[value.attribute_id] = value.id
      })

      this.currentAttributes = attrMap
      this.cartError.attribute = null

      this.selectedInventory = target
      this.optionChanged(target)
      this.syncImageToSelection()

      /*
       * A smaller variant may not have as many. Clamping here rather than
       * leaving it to the add-to-cart refusal means the stepper never shows a
       * quantity that cannot be bought.
       */
      const backorder = Number(target.is_active) === 1
      const stock = Number(target.quantity || 0)

      if (!backorder && this.quantity > stock) {
        this.quantity = Math.max(1, stock)
      }

      return true
    },

    selectedAttribute(data) {

      this.cartError.attribute = null
      const temp = { ...this.currentAttributes };
      temp[data.key.split('-')[0]] = data.value;
      if (!this.isCombinationAvailable(temp)) {
        return;
      }
      this.currentAttributes = temp;
      //this.attrHover(data.value)

      this.currentAttributes[data.key.split('-')[0]] = data.value

      this.syncImageToSelection()


      if (Object.values(this.currentAttributes).length === this.productAttributes.length) {

        const selected = Object.values(this.currentAttributes).map(i => {
          return i.attribute_value_id
        })

        const selectedAttr = selected.sort().join('-')

        let currentInventory = null
        const inventoryAttr = []


        for (var i of this.product?.inventory) {
          const invAttr = []
          i.inventory_attributes.forEach(j => {
            invAttr.push(parseInt(j.attribute_value_id))
          })

          inventoryAttr[invAttr.sort().join('-')] = i
        }

        if (inventoryAttr[selectedAttr]) {
          currentInventory = inventoryAttr[selectedAttr]
        }

        this.selectedInventory = currentInventory
        this.optionChanged(currentInventory)
      } else {
        this.selectedInventory = {}
      }
    },
    descriptionToggle() {
      this.descriptionExpand = !this.descriptionExpand
    },
    closeVoucherPopOver() {
      this.voucherPopOver = false
    },
    fetchedReview(evt) {
      this.hasReview = !!evt
      this.reviewLoaded = !!!evt
    },
    optionChanged(evt) {
      this.optionChange = true
      this.productInventory = evt

    },
    qty(direction) {
      const inventory = this.productInventory || {}
      const isBackOrder = inventory?.is_active == 1
      const stockQty = Number(inventory?.quantity || 0)
      if (this.quantity + direction <= 0) return
      if (!isBackOrder) {
        if (this.quantity + direction > stockQty) {
          return
        }
      }
      this.quantity += direction
    },
    ...mapActions('cart', ['cartAction', 'cartSwitchVariant']),
    ...mapActions('common', ['fetchLocation']),
    ...mapActions('detail', ['emptySuggestedProducts', 'refreshStock']),
    ...mapActions('user', ['emptyVoucher']),
  },
  beforeDestroy() {
    document.body.classList.remove('detail-page')
    // show-cart also drives the body's bottom clearance in mobile-chrome.styl,
    // so it must come off when leaving the product page
    document.body.classList.remove('show-cart')
    window.removeEventListener('resize', this.updateLayoutBreakpoint)
    // Clear the interval when component is destroyed
    if (this.countdownInterval) {
      clearInterval(this.countdownInterval);
    }
    if (this.successTimeout) {
      clearTimeout(this.successTimeout)
    }
    if (this.pricingDebounce) {
      clearTimeout(this.pricingDebounce)
    }
    if (this.stockTimer) {
      clearInterval(this.stockTimer)
    }
    if (this.onStockFocus) {
      document.removeEventListener('visibilitychange', this.onStockFocus)
    }
  },
  watch: {
    /*
     * The basket changed - an add from this page (main button, bundle,
     * upgrade, cross-sell) or anywhere else: the stock it reserved has left
     * the shelf, so the pill is re-read.
     */
    '$store.state.cart.cartCount'() {
      this.refreshStock()
    },
    // Watch for product data to be loaded
    product: {
      immediate: true,
      handler(newVal) {
        if (newVal && newVal.end_time) {
          this.$nextTick(() => {
            this.startCountdown();
          });
        }
        if (newVal && newVal.id) {
          this.fetchUpsellCrossSell()
        }
      }
    },
    /*
     * A different variation is a different price, and the server prices a
     * quantity against a variant — so re-quote when the customer picks one.
     * Without this the quantity box kept quoting the variant the page opened
     * on.
     */
    'selectedInventory.id'() {
      this.requoteBusinessPrice()
    }
  },
  async asyncData({ store, route, $auth, error }) {
    try {
      await store.dispatch('detail/fetchProduct', {
        params: {
          id: route.params.id,
          user_id: $auth?.user?.id || '',
          // Whose wishlist to answer with when nobody is signed in.
          user_token: $auth?.loggedIn ? '' : (store.getters['user/userToken'] || '')
        },
        lang: store.state?.language?.langCode,
      })

      // PAYMENT GATEWAYS
      if (!store.state.common.paymentGateway) {

        const data = await store.dispatch(
          'common/getRequest',
          {
            params: {},
            api: 'paymentGateway'
          }
        )

        store.commit(
          'common/SET_PAYMENT_GATEWAY',
          data.data
        )
      }
    } catch (e) {
      error(e)
    }
  },
  async mounted() {
    /*
     * Stock moves under an open product page: this shopper's own add takes
     * units at once, and so does anyone else's basket. Re-read it whenever
     * the basket changes, when the tab comes back into view, and once a
     * minute while it is open.
     */
    this.stockTimer = setInterval(() => this.refreshStock(), 60000)
    this.onStockFocus = () => { if (document.visibilityState === 'visible') this.refreshStock() }
    document.addEventListener('visibilitychange', this.onStockFocus)

    this.fetchBulkPricing()
    this.emptyVoucher()
    this.emptySuggestedProducts()
    //Checking if the product has no attribute
    // if (this.product?.inventory?.length === 1 && this.product?.inventory[0]?.inventory_attributes?.length === 0) {

    //   this.selectedInventory = this.product?.inventory[0]
    // }

    if (this.product?.inventory?.length) {
      /*
       * In-stock rows first, then a row that takes backorders. A product with
       * a single row and no attributes gets that row whatever its stock: there
       * is nothing for the shopper to choose, and leaving it unselected made
       * "Add to cart" ask them to "select all required attributes".
       */
      const inventories = [...this.product.inventory]
      const defaultInventory = inventories.filter(i =>
        Number(i.quantity) > 0
      ).sort((a, b) =>
        Number(b.quantity || 0) -
        Number(a.quantity || 0)
      )[0] ||
        inventories.find(i => i.is_active == 1) ||
        (inventories.length === 1 && !inventories[0].inventory_attributes?.length ? inventories[0] : null)
      if (defaultInventory) {
        this.selectedInventory = defaultInventory
        this.productInventory = defaultInventory
        this.optionChange = true
        const attrMap = {}
        ;(defaultInventory.inventory_attributes || []).forEach(attr => {

          const attribute = this.product.attribute.find(a =>
            a.values.some(v =>
              Number(v.attribute_value_id) === Number(attr.attribute_value_id)
            )
          )

          if (!attribute) return

          const value = attribute.values.find(v =>
            Number(v.attribute_value_id) === Number(attr.attribute_value_id)
          )

          if (!value) return

          attrMap[value.attribute_id] = {
            ...value,
            attribute_value_id: value.attribute_value_id
          }
          this.clickedAttributes[value.attribute_id] = value.id
        })
        this.currentAttributes = attrMap
      }
    }

    document.body.classList.add('detail-page')

    // The cart bar stays on screen for the whole of the product page. It used
    // to be toggled by an IntersectionObserver on the buy panel, which hid it
    // again whenever that panel scrolled into view.
    document.body.classList.add('show-cart')

    this.updateLayoutBreakpoint()
    window.addEventListener('resize', this.updateLayoutBreakpoint)

    // Start countdown if product data is already available
    if (this.product && this.product.end_time) {
      this.startCountdown();
    }

  }
}
</script>

<style>
.template-accordion {
  margin-top: 30px;
}

.accordion .accordion-body {
  margin-top: 15px;
}

.accordion .accordion-body p {
  font-size: 14px;
}


.accordion-header .accordion-button {
  padding: 0;
  line-height: normal;
  height: auto;
}

.accordion-header .accordion-button {
  padding: 0;
  font-size: 16px;
  font-weight: 600;
  color: rgba(19, 14, 43, 1);
}

.accordion-body {
  padding: 0;
}

.template-item {
  margin-bottom: 15px;
}

.template-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 15px;
  cursor: pointer;
  background: #f8f9fa;
  border-radius: 4px;
  transition: all 0.2s;
}

.template-header:hover {
  background: #e9ecef;
}

.template-content {
  padding: 15px;
  background: #fff;
  border: 1px solid #dee2e6;
  border-top: none;
  border-radius: 0 0 4px 4px;
}

.arrow-icon {
  transition: transform 0.2s;
}

.arrow-down {
  transform: rotate(0deg);
}

.arrow-up {
  transform: rotate(180deg);
}

.countdown-display {
  display: inline;
  font-weight: bold;
}

.pane-container {
  background: #fff !important;
}

.bulk-pricing-box {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 16px;
  background: #ffffff;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
}

/* HEADER */
.bulk-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.bulk-header h4 {
  font-size: 16px;
  font-weight: 600;
}

.bulk-tag {
  background: #eef2ff;
  color: #4f46e5;
  font-size: 11px;
  padding: 4px 8px;
  border-radius: 6px;
}

/* TABLE */
.bulk-table {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* ROW */
.bulk-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px;
  border-radius: 8px;
  background: #f9fafb;
  transition: 0.2s;
  border: 1px solid transparent;
}

.bulk-row:hover {
  background: #f1f5ff;
}

/* LEFT */
.bulk-left {
  display: flex;
  flex-direction: column;
}

.qty {
  font-weight: 600;
  font-size: 14px;
}

.label {
  font-size: 11px;
  color: #6b7280;
}

/* RIGHT */
.bulk-right {
  font-weight: 700;
  font-size: 16px;
  color: #111827;
}

/* THE BAND THE CUSTOMER IS CURRENTLY BUYING AT */
.bulk-row.best {
  background: #eef2ff;
  border-color: #6366f1;
}

.bulk-row.best .bulk-right {
  color: #4f46e5;
}

.bulk-applied {
  margin: 10px 0 0;
  font-size: 13px;
  color: #4f46e5;
}

.preview-box {
  border: 1px solid #e3e3ef;
  border-radius: 20px;
}

.badge-danger {
  border: 1px solid #ff5151;
  padding: 4px 13px 5px 13px;
  border-radius: 86px;
  color: #ff5151;
}

.badge-success {
  border: 1px solid #05b942;
  padding: 4px 13px 5px 13px;
  border-radius: 86px;
  color: #05b942;
}

.badge-secondary {
  border: 1px solid #605982;
  padding: 4px 13px 5px 13px;
  border-radius: 86px;
  color: #605982;
  margin-bottom: 25px;
}

.brand-image {
  height: 45px;
  width: auto;
  object-fit: contain;
  border: 1px solid #e3e3ef;
  border-radius: 15px;
}

.fmpc-attributes-title {
  font-size: 16px;
  font-weight: 600;
  color: rgba(19, 14, 43, 1);
  margin-bottom: 12px;
  line-height: 1em;
}

.btn-cart {
  background: #3f3d9c;
  color: #fff;
  font-size: 12px;
}

.btn-cart:hover {
  background: #3f3d9c;
  color: #fff;
}

.card {
  border-radius: 12px;
}

.img-fluid {
  width: 100px;
  height: 100px;
  object-fit: contain;
}

.disabled-attr {
  opacity: 0.4;
  background: #bbb;
  border-radius: 8px;
  cursor: not-allowed;
}

.cart-success-bar {
  width: 100%;
  background: rgba(244, 255, 246, 1);
  border: 1px solid rgba(5, 185, 66, 1);
  color: rgba(35, 33, 89, 1);
  padding: 10px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 14px;
  font-weight: 500;
  border-radius: 10px;
}

.success-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.check-icon {
  background: #28a745;
  color: white;
  border-radius: 50%;
  padding: 4px 8px;
  font-size: 12px;
}

.view-cart-btn {
  font-size: 14px;
  font-weight: 500;
  text-decoration: underline;
  text-underline-offset: 3px;
  height: auto;
  line-height: 0;
  color: rgba(5, 185, 66, 1);
}

.view-cart-btn:hover {
  color: #239b3f;
}

.voucher-applied-box {
  background: #f0fdf4;
  border: 1px solid #86efac;
  border-radius: 12px;
  padding: 14px;
  margin-top: 12px;
}

.voucher-applied-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.voucher-applied-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.voucher-success-icon {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: #22c55e;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: bold;
}

.config-sku {
  color: #33319a;
}

.voucher-applied-title {
  font-size: 13px;
  font-weight: 600;
  color: #166534;
}

.voucher-applied-code {
  font-size: 12px;
  color: #15803d;
}

.voucher-discount {
  font-size: 18px;
  font-weight: 700;
  color: #16a34a;
}

.voucher-final-price {
  margin-top: 10px;
  font-size: 13px;
  color: #374151;
}

.selected-config-box {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 14px;
  background: #f9fafb;
}

.config-header {
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 10px;
  color: #111827;
}

.config-values {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 16px;
  font-weight: 500;
  margin-top: 16px;
  margin-bottom: 7px;
}

.spec-technical-specifications,
.technical-specifications {
  width: 100%;
  margin-top: 40px;
  margin-bottom: 100px;
}

.spec-title {
  text-align: center;
  font-size: 30px;
  line-height: 1.2;
  font-weight: 700;
  color: #17162f;
  margin: 0 0 28px;
}

.spec-table-wrapper {
  width: 100%;
  overflow-x: auto;
  border: 1px solid #e4e6ef;
  border-radius: 12px;
  background: #fff;
}

.spec-table {
  width: 100%;
  min-width: 720px;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13px;
  line-height: 1.45;
  color: #17162f;
}

.spec-table thead th {
  background: #f7f6ff;
  color: #17162f;
  font-weight: 600;
  font-size: 16px;
  text-align: left;
  padding: 18px 24px;
  border-bottom: 1px solid #edf0f5;
}

.spec-table thead th:first-child {
  width: 43%;
}

.spec-table thead th:last-child {
  width: 57%;
}

.spec-table tbody td {
  padding: 15px 24px;
  border-bottom: 1px solid #edf0f5;
  vertical-align: top;
  font-size: 16px;
  font-weight: 400;
}

.spec-table tbody tr:last-child td {
  border-bottom: 0;
}

.spec-table th:first-child,
.spec-table td:first-child {
  border-right: 1px solid #edf0f5;
  font-weight: 600;
}

.spec-group {
  font-weight: 600;
  color: #17162f;
}

.spec-value {
  color: #17162f;
  font-weight: 400;
}

.spec-value strong {
  display: block;
  font-weight: 700;
  margin-bottom: 4px;
}

.accessories-and-upsell-main-card {
  /* 40px a side left almost no room in a narrow column, which is what pushed
     the bundle's button past the card edge. 20px is enough to breathe and
     leaves the content the width it needs. */
  padding: 20px;
  background: #f6f5fd;
  border: 1px solid #e3e3ef;
  border-radius: 14px;
}
@media (min-width: 992px) {
  .accessories-and-upsell-main-card { padding: 32px; }
}

.accessories-and-upsell-main-card .frequently-bought-together-title {
  font-size: 16px;
  font-weight: 600;
  color: #130e2b;
  letter-spacing: normal;
}

.accessories-and-upsell-main-card .text-danger {
  color: #f00726 !important;
}

.accessories-upgrades-cards-service-title .a-u-s-icon {
  line-height: 0;
}

.accessories-upgrades-card-header h6 {
  font-size: 16px;
  font-weight: 600;
  letter-spacing: 0;
  color: #130e2b;
}

.accessories-upgrades-cards-service-title {
  margin-bottom: 14px;
  font-size: 16px;
  font-weight: 600;
}

.essentials-bundle-div .accessories-upgrades-text {
  margin-top: 14px;
}

.accessories-upgrades-card-header .badge.bg-instock {
  padding: 5px 8px !important;
  line-height: normal;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
  background: #05b942;
  border-radius: 86px;
}

.accessories-upgrades-cards-service-title {
  margin-bottom: 20px;
  font-size: 16px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
}

.accessories-upgrades-card-header {
  display: flex;
  gap: 21px;
  margin-top: 23px;
  align-items: center;
}

.accessories-upgrades-cards {
  padding: 20px;
  background: #fff;
  border-radius: 12px;
}

.accessories-upgrades-cards:not(:first-child) {
  margin-top: 20px;
}

.accessories-upgrades-text {
  display: flex;
  flex-direction: column;
  gap: 15px;
}

.p_dt_checkbox-list {
  display: flex;
  justify-content: space-between;
  /* gap and top alignment so a label that wraps to two lines does not run
     into the price, and the price stays level with the first line */
  align-items: flex-start;
  gap: 12px;
  padding: 10px 0;
  font-size: 14px;
  font-weight: 500;
  color: #130e2b;
}

.p_dt_checkbox-list + .p_dt_checkbox-list {
  border-top: 1px solid #edeff3;
}

/* The name, its tick and -- for a product with a real choice -- its variant
   picker, kept together on the left so the price stays on the right of the
   row rather than being pushed about by the select. It wraps rather than
   squeezing the picker on a narrow screen. */
.f-b-t-row-main {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 10px;
  min-width: 0;
}

.f-b-t-option {
  max-width: 190px;
  padding: 5px 26px 5px 10px;
  border: 1px solid #d6d8e7;
  border-radius: 8px;
  background: #fff;
  font-size: 12.5px;
  font-weight: 600;
  color: #130e2b;
  /* The global button/input metrics in base.styl set a 50px height on
     controls; a 12.5px picker inside a 14px row is not one of them. */
  height: auto;
  line-height: 1.4;
  cursor: pointer;
}

/* Nothing chosen yet is the one state that asks for something, so it is the
   one state that is coloured. */
.f-b-t-option.is-unset {
  border-color: #05B942;
  color: #05B942;
}

.f-b-t-option:focus-visible {
  outline: 2px solid #05B942;
  outline-offset: 1px;
}

/* "From", while the chosen variant could still change the figure. */
.f-b-t-from {
  font-weight: 600;
  font-size: 12px;
  color: #6b7280;
}

.accessories-upgrades-price-span {
  flex: 0 0 auto;
  white-space: nowrap;
}

.accessories-upgrades-checkbox-span {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  line-height: 1.4;
  cursor: pointer;
}

.accessories-upgrades-checkbox-span input {
  appearance: none;
  -webkit-appearance: none;
  /* nudge onto the first line's optical centre now the row is top-aligned */
  margin-top: 1px;
  width: 18px;
  height: 18px;
  min-width: 18px;
  border: 2px solid #b7b7c3;
  border-radius: 4px;
  background: #fff;
  position: relative;
  margin: 0;
}

.accessories-upgrades-checkbox-span input:checked {
  background: #b7b7c3;
  border-color: #b7b7c3;
}

.accessories-upgrades-checkbox-span input:checked::before {
  content: "";
  position: absolute;
  width: 12px;
  height: 9px;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background-image: url("data:image/svg+xml,%3Csvg fill='none' xmlns='http://www.w3.org/2000/svg' width='12' height='9' viewBox='0 0 12 9'%3E%3Cpath d='M10.3333 1L3.91667 7.41667L1 4.5' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: center;
}

.p_detail-breadcrumb {
  padding: 20px 0;
  background: rgba(247, 247, 250, 1);
}

.p_detail-breadcrumb nav a {
  font-size: 14px;
  font-weight: normal;
}

.p_detail-breadcrumb nav {
  margin: 0 !important;
}

.p_detail-breadcrumb .breadcrumb li:last-child {
  color: rgba(51, 49, 153, 1);
  font-weight: 600;
}

.essentials-bundle-div p {
  font-size: 14px;
  font-weight: 400;
  color: rgba(19, 14, 43, 1);
  font-style: italic;
  margin-bottom: 14px;
}

.fmpc-vouchers {
  padding: 15px;
  background: #fffadd;
  border: 1px solid #d3c092;
  border-radius: 8px;
}
.fmpc-vouchers .fmpc-attributes-title {
  color: #633939;
}
.fmpc-voucher-pills {
  display: flex;
  gap: 10px;
}

.fmpc-voucher-pills .info-msg {
  padding: 10px 14px;
  font-size: 14px;
  font-weight: 600;
  color: #633939;
  background: transparent;
  border-color: #e3e3ef;
  border-radius: 8px;
  font-size: 14px;
  position: relative;
  border: 1px solid;
  cursor: pointer;
}

.fmpc-color-atrr-list {
  display: flex;
  align-items: center;
  gap: 10px;
}

.fmpc-color-atrr-item {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  border: 2px solid transparent;
  flex-shrink: 0;
}

.fmpc-color-atrr-circle {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  display: block;
  border: 1px solid #e5e5e5;
}

/* Active State */
.fmpc-color-atrr-item.active {
  border: 2px solid #0d6efd;
}

.fmpc-color-atrr-item.active .fmpc-color-atrr-circle {
  border: 2px solid #fff;
}

.fmpc-grading-guide {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 16px;
  font-weight: 500;
  line-height: 100%;
  color: #33319a;
  text-decoration: none;
}

.fmpc-grading-guide__icon {
  width: 20px;
  height: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.fmpc-grading-guide__icon svg {
  width: 20px;
  height: 20px;
  display: block;
}

.fmpc-grading-guide__text {
  color: #33319a;
  white-space: nowrap;
}
.add-basket-extras {
  font-size: 14px;
  font-weight: 600;
  padding: 15px 25px;
  line-height: normal;
  height: auto;
  border-radius: 10px;
  background: rgba(51, 49, 154, 1);
}
@media (max-width: 767px) {
  .spec-title {
    font-size: 24px;
    margin-bottom: 20px;
  }

  .spec-table {
    min-width: 620px;
    font-size: 12px;
  }

  .spec-table thead th,
  .spec-table tbody td {
    padding: 13px 16px;
  }

  .fmpc-detail-side-by-side-attributes .start {
    justify-content: start;
  }
  .accessories-and-upsell-main-card {
    padding: 15px;
  }
}

.product-intro-section .intro-title {
  font-size: 40px;
  font-weight: 600;
  text-align: center;
  color: #130e2b;
  margin-bottom: 22px;
  margin-top: 70px;
}

.intro-description p {
  text-align: center;
  font-weight: 400;
  font-size: 18px;
  color: #130e2b;
}

.feature-images-wrapper {
  margin-top: 40px;
  display: flex;
  flex-direction: column;
  gap: 30px;
}

.feature-image-item {
  width: 100%;
}

.fmpc-detail-side-by-side-attributes {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
  row-gap: 24px;
  margin: 20px 0px;
}

.feature-image {
  width: 100%;
  border-radius: 20px;
  display: block;
}
@media (max-width: 500px) {
  .fmpc-detail-side-by-side-attributes {
    grid-template-columns: 1fr;
    row-gap: 20px;
  }
}
.fmpc-trust-info {
    background: #ECFDE8;
    padding: 48px 24px;
    margin-top: 40px;
}

.fmpc-trust-info__grid {
  max-width: 1200px;
  margin: 0 auto;

  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 60px;
}

.fmpc-trust-info__item {
  text-align: center;
}

.fmpc-trust-info__icon {
  width: 84px;
  height: 57px;

  display: flex;
  align-items: center;
  justify-content: center;

  margin: 0 auto 32px;
}

.fmpc-trust-info__icon img {
    height: 68px;
    object-fit: contain;
}
.fmpc-trust-info__title {
  margin: 0 0 24px;
  font-size: 24px;
  font-weight: 600;
  line-height: 100%;
  color: #333199;
}

.fmpc-trust-info__description {
  margin: 0 auto;
  max-width: 374px;

  font-family: Inter, sans-serif;
  font-size: 20px;
  font-weight: 400;
  line-height: 120%;
  color: #130e2b;
}
.fmpc-detail-side-by-side-attributes .flex {
    display: flex;
    justify-content: flex-start;
}
/*
 * Frequently bought together.
 *
 * Sizes itself from the space it is given, not from the viewport. The block is
 * often rendered inside a narrow column (device previews, split layouts), where
 * a viewport media query never fires and the old layout pushed its button
 * outside the card. Everything below reflows on wrap instead.
 */
.frequently-bought-together-grid {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px 16px;
    margin-top: 12px;
}
.frequently-bought-together-grid-image-with-plus {
    /*
     * Take only the width the thumbnails need, and shrink when there is not
     * enough. flex-grow was 1, so with two thumbnails in a wide card the track
     * stretched across the whole row and left a large empty area before the
     * price. The summary does the growing instead.
     */
    flex: 0 1 auto;
    min-width: 0;
}
.frequently-bought-together-grid-image-with-plus-grid {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 6px;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 transparent;
    padding-bottom: 4px;

    /*
     * Scroll shadows. The two "local" layers scroll with the content and cover
     * the shadows at each end, so a shadow only appears on a side there is
     * more to scroll towards — and nothing shows at all when everything fits.
     * A fixed fade would have sat on top of the last thumbnail instead.
     */
    background:
      linear-gradient(to right, #f6f5fd 30%, rgba(246, 245, 253, 0)) left center,
      linear-gradient(to left,  #f6f5fd 30%, rgba(246, 245, 253, 0)) right center,
      radial-gradient(farthest-side at 0 50%,   rgba(19, 14, 43, .14), rgba(19, 14, 43, 0)) left center,
      radial-gradient(farthest-side at 100% 50%, rgba(19, 14, 43, .14), rgba(19, 14, 43, 0)) right center;
    background-repeat: no-repeat;
    background-size: 26px 100%, 26px 100%, 11px 100%, 11px 100%;
    background-attachment: local, local, scroll, scroll;
}
.frequently-bought-together-grid-image-with-plus-grid::-webkit-scrollbar { height: 4px; }
.frequently-bought-together-grid-image-with-plus-grid::-webkit-scrollbar-track { background: transparent; }
.frequently-bought-together-grid-image-with-plus-grid::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 2px; }

.f-b-t-image-plus {
    display: flex;
    align-items: center;
    flex: 0 0 auto;
}
.f-b-t-image-plus img {
    width: 56px;
    height: 56px;
    object-fit: contain;
    background: #fff;
    padding: 5px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}
.f-b-t-plus {
    font-size: 15px;
    font-weight: 600;
    line-height: 1;
    color: #94a3b8;
    flex: 0 0 auto;
    user-select: none;
}

/* price and call to action: side by side, dropping to their own line together
   once there is no longer room beside the thumbnails */
/*
 * Price and call to action share a grid whose columns always consume the whole
 * track: two side by side when there is room for both, otherwise one stacked
 * column. With flex the button kept sizing to its own basis and left a strip of
 * dead space at the right of the card.
 */
.frequently-bought-together-grid-text-div {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 165px), 1fr));
    align-items: center;
    gap: 10px 12px;
    flex: 1 1 240px;
    min-width: 0;
}
.f-b-t-total {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1px;
    margin: 0;
    min-width: 0;
}
.f-b-t-total-label {
    font-size: 11px;
    line-height: 1.3;
    color: #64748b;
}
.f-b-t-price-row {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 6px;
}
.f-b-t-total-value {
    font-size: 20px;
    font-weight: 700;
    line-height: 1.2;
    color: #130e2b;
}
.f-b-t-was {
    font-size: 13px;
    color: #94a3b8;
}
.f-b-t-save {
    font-size: 11px;
    font-weight: 600;
    line-height: 1.4;
    color: #067647;
}
.add-basket-extras {
    /* fills its grid cell, whether that cell is a column or the whole row */
    width: 100%;
    white-space: nowrap;
    padding: 13px 18px;
}
.add-basket-extras:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.essentials-bundle-plus-img .img-fluid {
    width: 80px;
    height: 80px;
    padding: 10px;
}
.spec-mobile-card {
  background: #fff;
  border: 1px solid #edf0f5;
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 12px;
}

.spec-mobile-card h4 {
  margin: 0;
  padding: 14px 16px;
  background: #f7f6ff;
  font-size: 15px;
  font-weight: 600;
  color: #17162f;
}

.spec-mobile-item {
  padding: 14px 16px;
  border-top: 1px solid #edf0f5;
  font-size: 14px;
  color: #17162f;
}
/* Tablet */
@media (max-width: 991px) {
    .detail-image-wrapper .col-lg-12 {
      padding: 0;
    }
  .fmpc-trust-info__grid {
    grid-template-columns: 1fr;
    gap: 50px;
  }

  .fmpc-trust-info__title {
    font-size: 22px;
  }

  .fmpc-trust-info__description {
    font-size: 18px;
  }
  .product-intro-section .intro-title{
    font-size: 30px;
  }
  .spec-technical-specifications,
  .technical-specifications {
     margin-bottom: 40px;
  }
}

/* Mobile */
@media (max-width: 575px) {
  .fmpc-trust-info {
    padding: 32px 20px;
  }

  .fmpc-trust-info__icon {
    width: 70px;
    height: 48px;
    margin-bottom: 24px;
  }

  .fmpc-trust-info__title {
    font-size: 20px;
    margin-bottom: 16px;
  }

  .fmpc-trust-info__description {
    font-size: 16px;
  }
  /* the bundle's own stacking is handled at 768px, where it actually breaks */

}
@media (max-width:400px) {
.accessories-upgrades-price-span {
    text-align: right;
}
.text-muted.text-decoration-line-through.me-2 {
    margin: 0px !important;
}
}

</style>
