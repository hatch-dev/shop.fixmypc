<template>
  <div v-if="$can('category', 'view')">

    <!-- Top Bar -->
    <div class="top-bar">
      <div class="page-header">
        <div class="breadcrumb">
          <span>Store</span>
          <i class="fa fa-chevron-right"></i>
          Categories
        </div>
        <div class="page-title">Category Management</div>
        <p class="page-subtitle">
          Organise your catalogue into categories and subcategories, and control
          what customers see on the storefront.
        </p>
      </div>

      <div class="page-actions">
        <div class="search-box">
          <i class="fa fa-search"></i>
          <label class="sr-only" for="category-search">Search categories</label>
          <input
            id="category-search"
            type="search"
            placeholder="Search name, slug or parent"
            v-model="search"
            @input="onSearchInput"
          >
          <button
            v-if="search"
            class="search-clear"
            type="button"
            title="Clear search"
            aria-label="Clear search"
            @click="clearSearch"
          >
            <i class="fa fa-times"></i>
          </button>
        </div>

        <button
          class="btn btn-filter"
          type="button"
          :class="{ 'is-on': showFilter || activeFilterCount > 0 }"
          :aria-expanded="showFilter ? 'true' : 'false'"
          aria-controls="category-filters"
          @click="showFilter = !showFilter"
        >
          <i class="fa fa-filter"></i> Filters
          <span v-if="activeFilterCount" class="filter-count">{{ activeFilterCount }}</span>
        </button>

        <button
          v-if="$can('category', 'create')"
          class="btn black"
          type="button"
          @click="addCategory"
        >
          <i class="fa fa-plus"></i> Add Category
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div v-show="showFilter" id="category-filters" class="filter-panel">
      <div class="container">
        <div class="filter-grid">
          <div class="filter-item">
            <label for="filter-status">Status</label>
            <select id="filter-status" v-model="filters.status" @change="applyFilters">
              <option value="">All statuses</option>
              <option value="1">Published</option>
              <option value="2">Draft</option>
            </select>
          </div>

          <div class="filter-item">
            <label for="filter-scope">Level</label>
            <select id="filter-scope" v-model="filters.scope" @change="applyFilters">
              <option value="">All levels</option>
              <option value="root">Root categories only</option>
              <option value="sub">Subcategories only</option>
            </select>
          </div>

          <div class="filter-item">
            <label for="filter-parent">Parent category</label>
            <select id="filter-parent" v-model="filters.parent" :disabled="parentsLoading" @change="applyFilters">
              <option value="">Any parent</option>
              <option value="none">No parent (root)</option>
              <option
                v-for="option in parentOptions"
                :key="option.id"
                :value="option.id"
              >{{ option.label }}</option>
            </select>
            <span v-if="parentsLoading" class="filter-hint">Loading categories…</span>
          </div>

          <div class="filter-item">
            <label for="filter-products">Products</label>
            <select id="filter-products" v-model="filters.has_products" @change="applyFilters">
              <option value="">Any</option>
              <option value="1">With products</option>
              <option value="0">Without products</option>
            </select>
          </div>

          <div class="filter-item">
            <label for="filter-sort">Sort by</label>
            <select id="filter-sort" v-model="orderby" @change="applyFilters">
              <option v-for="(label, key) in sortOptions" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>

          <div class="filter-item">
            <label for="filter-direction">Direction</label>
            <select id="filter-direction" v-model="ordertype" @change="applyFilters">
              <option value="desc">Descending</option>
              <option value="asc">Ascending</option>
            </select>
          </div>

          <div class="filter-item filter-reset-cell">
            <button class="reset-btn" type="button" @click="resetFilter">
              <i class="fa-solid fa-rotate-right"></i> Reset filters
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Summary cards -->
    <div class="cards">
      <div class="container">
        <div v-for="card in summaryCards" :key="card.key" class="card">
          <div class="card-inr" :class="card.tone + '-col'">
            <div class="icon-box" :class="card.tone"><i :class="card.icon"></i></div>
            <div v-if="card.badge" class="card-prcn" :class="card.badgeClass">{{ card.badge }}</div>
          </div>
          <div class="cart-cnt">
            <h4>{{ card.title }}</h4>
            <h2 v-if="!loading || loaded">{{ card.value }}</h2>
            <div v-else class="stat-skeleton" aria-hidden="true"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="table-box">
      <div class="container">

        <div class="table-header">
          <div>
            <h3>All Categories</h3>
            <p>{{ resultSummary }}</p>
            <!--
              The tree is assembled in one pass over the matching set, so it
              has a ceiling. Saying so beats quietly showing part of the
              catalogue as though it were all of it.
            -->
            <p v-if="pagination.truncated" class="tree-truncated">
              <i class="fa-solid fa-triangle-exclamation"></i>
              This is more of the catalogue than the tree can arrange at once.
              Narrow it with a search or a filter, or use the flat view.
            </p>
          </div>
          <div class="table-actions">

            <!--
              Tree is the default, because "which parent does this belong to?"
              is the question the list is usually opened to answer. Flat is
              kept because the tree can only order siblings — sorting the whole
              catalogue by, say, product count is a flat question.
            -->
            <div class="view-toggle" role="group" aria-label="List layout">
              <button
                type="button"
                :class="{ on: isTree }"
                :aria-pressed="String(isTree)"
                title="Group subcategories under their parent"
                @click="setView('tree')"
              >
                <i class="fa fa-sitemap"></i> Tree
              </button>
              <button
                type="button"
                :class="{ on: !isTree }"
                :aria-pressed="String(!isTree)"
                title="One flat, fully sortable list"
                @click="setView('flat')"
              >
                <i class="fa fa-list-ul"></i> Flat
              </button>
            </div>

            <button
              v-if="isTree"
              class="btn"
              type="button"
              :disabled="loading || !expandableCount"
              :title="allExpanded ? 'Collapse every parent' : 'Expand every parent'"
              @click="allExpanded ? collapseAll() : expandAll()"
            >
              <i class="fa" :class="allExpanded ? 'fa-compress' : 'fa-expand'"></i>
              {{ allExpanded ? 'Collapse all' : 'Expand all' }}
            </button>

            <div class="limit-box">
              <label for="page-limit">Show</label>
              <select id="page-limit" v-model="limit" @change="applyFilters">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
              </select>
              <span>{{ isTree ? 'parents' : 'entries' }}</span>
            </div>
            <button class="btn" type="button" :disabled="exporting || !hasRows" @click="exportCategories">
              <i class="fa fa-download"></i> {{ exporting ? 'Exporting…' : 'Export' }}
            </button>
            <button class="btn" type="button" :disabled="loading" @click="refresh">
              <i class="fa fa-rotate" :class="{ 'fa-spin': loading }"></i> Refresh
            </button>
          </div>
        </div>

        <!-- Bulk action bar -->
        <div v-if="cbList.length" class="bulk-bar">
          <span class="bulk-count">
            <strong>{{ cbList.length }}</strong>
            {{ cbList.length === 1 ? 'category' : 'categories' }} selected
          </span>
          <div class="bulk-actions">
            <button
              v-if="$can('category', 'edit')"
              class="btn bulk-btn"
              type="button"
              :disabled="bulkWorking"
              @click="bulkStatus(1)"
            >
              <i class="fa-solid fa-circle-check"></i> Publish
            </button>
            <button
              v-if="$can('category', 'edit')"
              class="btn bulk-btn"
              type="button"
              :disabled="bulkWorking"
              @click="bulkStatus(2)"
            >
              <i class="fa-regular fa-circle"></i> Move to draft
            </button>
            <button
              v-if="$can('category', 'delete')"
              class="btn bulk-btn danger"
              type="button"
              :disabled="bulkWorking"
              @click="deleteBulk"
            >
              <i class="fa fa-trash"></i> Delete
            </button>
            <button class="btn bulk-btn ghost" type="button" @click="cbList = []">Clear</button>
          </div>
        </div>

        <!-- Error -->
        <div v-if="errorMessage && !loading" class="state-box error-state" role="alert">
          <div class="state-icon danger"><i class="fa-solid fa-triangle-exclamation"></i></div>
          <h4>We couldn't load your categories</h4>
          <p>{{ errorMessage }}</p>
          <button class="button primary-btn" type="button" @click="refresh">Try again</button>
        </div>

        <div v-else class="table-scroll">
          <table class="category-table">
            <thead>
              <tr>
                <th class="col-checkbox">
                  <input
                    type="checkbox"
                    title="Select all on this page"
                    aria-label="Select all categories on this page"
                    :checked="allOnPageSelected"
                    :indeterminate.prop="someOnPageSelected"
                    :disabled="!hasRows"
                    @change="toggleSelectAll($event)"
                  >
                </th>
                <th class="col-thumb">Image</th>
                <th
                  v-for="column in visibleColumns"
                  :key="column.key"
                  class="sortable"
                  :class="column.class"
                  :aria-sort="ariaSort(column.key)"
                  tabindex="0"
                  role="button"
                  @click="sortBy(column.key)"
                  @keydown.enter.prevent="sortBy(column.key)"
                  @keydown.space.prevent="sortBy(column.key)"
                >
                  {{ column.label }}
                  <i :class="sortIcon(column.key)"></i>
                </th>
                <th class="col-actions">Actions</th>
              </tr>
            </thead>

            <!-- Skeleton -->
            <tbody v-if="loading">
              <tr v-for="row in skeletonRows" :key="'skeleton-' + row" class="skeleton-row">
                <td><span class="sk sk-box"></span></td>
                <td><span class="sk sk-thumb"></span></td>
                <!-- Driven by the columns actually on screen: the tree view
                     drops the Parent column, and a fixed run of cells left the
                     placeholder one wider than the table it stands in. -->
                <td v-for="column in visibleColumns" :key="'skeleton-cell-' + column.key">
                  <span class="sk sk-line" :class="column.key === 'title' ? 'w-70' : 'w-40'"></span>
                  <span v-if="column.key === 'title'" class="sk sk-line w-40 sk-sm"></span>
                </td>
                <td><span class="sk sk-line w-40"></span></td>
              </tr>
            </tbody>
            <!-- Rows -->
            <tbody v-else-if="hasRows">
              <template v-for="row in displayRows">

                <!--
                  A parent with nothing under it. Drawn as a row of its own so
                  the hierarchy reads the same whether a branch is full or
                  empty, rather than leaving the eye to work out that the next
                  bold line is a different parent.
                -->
                <tr v-if="row.placeholder" :key="row.key" class="tree-placeholder-row">
                  <td class="col-checkbox"></td>
                  <td class="col-thumb"></td>
                  <!--
                    The flex lives on an inner element, not the cell: a td set
                    to display:flex stops being a table cell, so its colspan is
                    ignored and it shrinks to its own content — which wrapped
                    this one line of text into three.
                  -->
                  <td class="tree-placeholder-cell" :colspan="columnCount - 2">
                    <div class="tree-placeholder-inner">
                      <span class="tree-guide" aria-hidden="true">
                        <span class="tree-elbow last"></span>
                      </span>
                      <span v-if="row.hidden" class="tree-placeholder-text">
                        {{ row.hidden }} {{ row.hidden === 1 ? 'subcategory' : 'subcategories' }}
                        hidden by the current filters
                      </span>
                      <span v-else class="tree-placeholder-text">No subcategories</span>
                      <button
                        v-if="!row.hidden && row.parent.can_have_children && $can('category', 'create')"
                        class="tree-add-btn"
                        type="button"
                        @click="addChild(row.parent)"
                      >
                        <i class="fa fa-plus"></i> Add one
                      </button>
                    </div>
                  </td>
                </tr>

                <tr
                  v-else
                  :key="row.id"
                  class="clickable-row"
                  :class="rowClass(row)"
                  tabindex="0"
                  @click="editNode(row)"
                  @keydown.enter="editNode(row)"
                >
                  <td class="col-checkbox" @click.stop>
                    <input
                      type="checkbox"
                      :value="row.id"
                      v-model="cbList"
                      :aria-label="'Select ' + row.title"
                    >
                  </td>

                  <td class="col-thumb">
                    <span class="thumb-frame">
                      <img
                        class="category-thumb"
                        loading="lazy"
                        decoding="async"
                        width="44"
                        height="44"
                        :src="rowImage(row)"
                        :alt="row.title ? row.title + ' category image' : 'Category image'"
                        @error="onImageError"
                      >
                    </span>
                  </td>

                  <td class="col-category">
                    <div class="category-cell">

                      <!--
                        The gutter. One slot per level above this row, filled
                        in where that ancestor still has siblings below it, and
                        then the elbow into the row itself. Purely decorative,
                        so it is hidden from assistive tech — the row's own
                        aria-level carries the same fact.
                      -->
                      <span v-if="isTree && rowDepth(row)" class="tree-guide" aria-hidden="true">
                        <span
                          v-for="(line, index) in gutters(row)"
                          :key="'gutter-' + index"
                          class="tree-gutter"
                          :class="{ 'has-line': line }"
                        ></span>
                        <span class="tree-elbow" :class="{ last: row.is_last_child }"></span>
                      </span>

                      <button
                        v-if="isTree && !rowDepth(row)"
                        class="tree-toggle"
                        type="button"
                        :class="{ empty: !branchSize(row) }"
                        :disabled="!branchSize(row)"
                        :aria-expanded="branchSize(row) ? String(!isCollapsed(row)) : null"
                        :title="branchTitle(row)"
                        :aria-label="branchTitle(row)"
                        @click.stop="toggleBranch(row)"
                      >
                        <i :class="branchIcon(row)"></i>
                      </button>

                      <i v-if="isTree" class="tree-icon" :class="treeIcon(row)" aria-hidden="true"></i>

                      <div class="cel-inr">
                        <span class="cat-name">{{ row.title }}</span>
                        <span class="cat-slug">/{{ row.slug }}</span>
                      </div>

                      <span
                        v-if="isTree && !rowDepth(row) && branchSize(row)"
                        class="branch-count"
                        :title="branchSize(row) + ' subcategories'"
                      >{{ branchSize(row) }}</span>

                      <span
                        v-if="!isTree"
                        class="category-type"
                        :class="isSub(row) ? 'secondary' : 'primary'"
                      >{{ isSub(row) ? 'Subcategory' : 'Root' }}</span>

                      <!--
                        Only reachable from a search or a filter: this row is
                        here so its children have somewhere to hang, not
                        because it matched. Saying so stops it reading as a
                        result that should not be there.
                      -->
                      <span v-if="isContext(row)" class="context-chip" title="Shown so its subcategories have a parent">
                        context
                      </span>
                    </div>
                  </td>

                  <td v-if="!isTree" class="col-parent">
                    <span v-if="row.parent_title" class="parent-chip">
                      <i class="fa-solid fa-turn-up fa-rotate-90"></i> {{ row.parent_title }}
                    </span>
                    <span v-else class="muted">&mdash;</span>
                  </td>

                  <td class="col-count">
                    <span class="count-chip" :class="{ zero: !Number(row.children_count) }">
                      {{ row.children_count || 0 }}
                    </span>
                  </td>

                  <td class="col-count">
                    <span class="count-chip" :class="{ zero: !Number(row.products_count) }">
                      {{ row.products_count || 0 }}
                    </span>
                  </td>

                  <td class="col-status" @click.stop>
                    <button
                      v-if="$can('category', 'edit')"
                      class="badge badge-btn"
                      type="button"
                      :class="Number(row.status) === 1 ? 'green' : 'orange'"
                      :disabled="busyId === row.id"
                      :title="Number(row.status) === 1 ? 'Published — click to move to draft' : 'Draft — click to publish'"
                      @click="toggleStatus(row)"
                    >
                      <i :class="statusIcon(row)"></i>
                      {{ Number(row.status) === 1 ? 'Published' : 'Draft' }}
                    </button>
                    <span v-else class="badge" :class="Number(row.status) === 1 ? 'green' : 'orange'">
                      <i class="fa-solid fa-circle-check"></i>
                      {{ Number(row.status) === 1 ? 'Published' : 'Draft' }}
                    </span>
                  </td>

                  <td class="col-order">{{ row.display_order }}</td>
                  <td class="col-date">{{ formatDate(row.created_at) }}</td>
                  <td class="col-date">{{ formatDate(row.updated_at) }}</td>

                  <td class="actions col-actions" @click.stop>
                    <div class="action-menu">
                      <button
                        class="icon-btn"
                        type="button"
                        title="Edit category"
                        aria-label="Edit category"
                        @click="editNode(row)"
                      >
                        <i class="fa fa-pen"></i>
                      </button>
                      <button
                        class="icon-btn"
                        type="button"
                        title="More actions"
                        :aria-expanded="menuId === row.id ? 'true' : 'false'"
                        aria-label="More actions"
                        @click="toggleMenu(row)"
                      >
                        <i class="fa fa-ellipsis-vertical"></i>
                      </button>

                      <div v-if="menuId === row.id" class="action-dropdown" @click.stop>
                        <button type="button" @click="editNode(row)">
                          <i class="fa fa-pen"></i> Edit
                        </button>
                        <!--
                          Offered only where the server would accept one. The
                          menu used to show it on every row, including the ones
                          already at the depth limit, where it opened a form
                          that could only be refused on save.
                        -->
                        <button
                          v-if="canAddChild(row) && $can('category', 'create')"
                          type="button"
                          @click="addChild(row)"
                        >
                          <i class="fa fa-plus"></i> Add subcategory
                        </button>
                        <button type="button" @click="openEmbedModal(row)">
                          <i class="fa-solid fa-code"></i> Embed code
                        </button>
                        <button
                          v-if="$can('category', 'delete')"
                          class="danger"
                          type="button"
                          @click="deleteNode(row)"
                        >
                          <i class="fa fa-trash"></i> Delete
                        </button>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
            <!-- Empty -->
            <tbody v-else>
              <tr>
                <td :colspan="columnCount">
                  <div v-if="isFiltered" class="state-box">
                    <div class="state-icon"><i class="fa fa-magnifying-glass"></i></div>
                    <h4>No categories match your filters</h4>
                    <p>Try a different search term, or clear the filters to see everything.</p>
                    <button class="button primary-btn" type="button" @click="resetFilter">
                      Clear filters
                    </button>
                  </div>
                  <div v-else class="state-box">
                    <div class="state-icon"><i class="fa fa-layer-group"></i></div>
                    <h4>No categories yet</h4>
                    <p>Categories group your products so customers can browse them. Create your first one to get started.</p>
                    <button
                      v-if="$can('category', 'create')"
                      class="button primary-btn"
                      type="button"
                      @click="addCategory"
                    >
                      <i class="fa fa-plus"></i> Add Category
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="pagination" v-if="!loading && pagination.total">
          <p class="shw-cat">
            Showing <span>{{ pagination.from }}</span> - <span>{{ pagination.to }}</span>
            of <span>{{ pagination.total }}</span>
            {{ isTree ? (pagination.total === 1 ? 'parent category' : 'parent categories') : 'results' }}
            <template v-if="isTree && subRowCount">
              &middot; <span>{{ subRowCount }}</span>
              {{ subRowCount === 1 ? 'subcategory' : 'subcategories' }}
            </template>
          </p>
          <div class="page-buttons" role="navigation" aria-label="Pagination">
            <button
              class="page-btn"
              type="button"
              :disabled="pagination.current_page <= 1"
              aria-label="Previous page"
              @click="changePage(pagination.current_page - 1)"
            >
              <i class="fa fa-chevron-left"></i>
            </button>
            <button
              v-for="page in pageWindow"
              :key="'p' + page.key"
              class="page-btn"
              type="button"
              :class="{ active: page.value === pagination.current_page, gap: page.gap }"
              :disabled="page.gap"
              :aria-current="page.value === pagination.current_page ? 'page' : null"
              @click="!page.gap && changePage(page.value)"
            >{{ page.label }}</button>
            <button
              class="page-btn"
              type="button"
              :disabled="pagination.current_page >= pagination.last_page"
              aria-label="Next page"
              @click="changePage(pagination.current_page + 1)"
            >
              <i class="fa fa-chevron-right"></i>
            </button>
          </div>
        </div>

      </div>
    </div>

    <!-- EMBED MODAL -->
    <div v-if="showEmbed" class="category-embed-modal" @click.self="showEmbed = false">
      <div class="embed-box" role="dialog" aria-modal="true" aria-label="Category embed code">
        <div class="embed-header">
          <h3>Embed &ldquo;{{ selectedCategory && selectedCategory.title }}&rdquo;</h3>
          <button class="close" type="button" aria-label="Close" @click="showEmbed = false">&times;</button>
        </div>
        <div class="embed-body">
          <label>Layout</label>
          <select v-model="embed.layout">
            <option value="horizontal">Horizontal</option>
            <option value="grid">Grid</option>
          </select>

          <label>Products</label>
          <select v-model="embed.mode">
            <option value="normal">Latest</option>
            <option value="offers">On offer</option>
            <option value="all">All</option>
          </select>

          <label v-if="embed.mode !== 'all'">How many</label>
          <input v-if="embed.mode !== 'all'" type="number" min="1" max="24" v-model.number="embed.limit">

          <label>Embed code</label>
          <textarea class="embed-code" readonly rows="3" :value="generatedCode"></textarea>

          <button class="button primary-btn" type="button" @click="copyCode">
            <i class="fa fa-copy"></i> Copy code
          </button>
        </div>
      </div>
    </div>

    <!-- DELETE CONFIRMATION -->
    <confirm-dialog
      :open="showDeleteConfirm"
      :title="deleteType === 'bulk' ? 'Delete categories?' : 'Delete category?'"
      warning="This cannot be undone."
      :confirm-label="deleteType === 'bulk' ? 'Delete categories' : 'Delete category'"
      confirm-icon="fa-solid fa-trash"
      busy-label="Deleting…"
      :busy="deleting"
      :confirm-disabled="impactLoading"
      @cancel="closeDelete"
      @confirm="executeDelete"
    >
      <template v-slot:subject>
        <template v-if="deleteType === 'single'">
          You are about to delete <strong>{{ deleteTarget && deleteTarget.title }}</strong>.
        </template>
        <template v-else>
          You are about to delete <strong>{{ deleteTarget ? deleteTarget.length : 0 }}</strong> categories.
        </template>
      </template>

        <div v-if="impactLoading" class="impact-box" aria-busy="true">
          <span class="sk sk-line w-70"></span>
          <span class="sk sk-line w-40 sk-sm"></span>
        </div>

        <div v-else-if="impact" class="impact-box">
          <!--
             Each sentence is one flex item, not several. The line is a flex
             row so the icon can hang beside wrapped text, and a bare
             <strong> in among the words became an item of its own - so the
             row's gap opened up either side of every number, and a sentence
             read "Contains  3  subcategories."
          -->
          <p v-if="impact.subcategories" class="impact-line warn">
            <i class="fa fa-sitemap" aria-hidden="true"></i>
            <span>
              Contains <strong>{{ impact.subcategories }}</strong>
              {{ impact.subcategories === 1 ? 'subcategory' : 'subcategories' }}.
            </span>
          </p>
          <p v-if="impact.products_including_subcategories" class="impact-line warn">
            <i class="fa fa-box" aria-hidden="true"></i>
            <span>
              <strong>{{ impact.products_including_subcategories }}</strong>
              {{ impact.products_including_subcategories === 1 ? 'product is' : 'products are' }}
              linked. They will stay in your catalogue but lose this category.
            </span>
          </p>
          <p v-if="!impact.subcategories && !impact.products_including_subcategories" class="impact-line ok">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>
              Nothing else is linked to
              {{ deleteType === 'bulk' ? 'these categories' : 'this category' }}.
            </span>
          </p>
        </div>

        <fieldset v-if="impact && impact.subcategories" class="children-choice">
          <legend>What should happen to the subcategories?</legend>
          <label>
            <input type="radio" value="reparent" v-model="childrenMode">
            <span>
              <strong>Move them up</strong>
              <em>They become children of {{ parentOfTargetLabel }}.</em>
            </span>
          </label>
          <label>
            <input type="radio" value="cascade" v-model="childrenMode">
            <span>
              <strong>Delete them too</strong>
              <em>All {{ impact.subcategories }} will be permanently removed.</em>
            </span>
          </label>
        </fieldset>

    </confirm-dialog>

  </div>
</template>

<script>

  import util from '~/mixin/util'
  import ConfirmDialog from '~/components/ConfirmDialog'
  import { mapActions } from 'vuex'

  /**
   * The admin category list.
   *
   * Rewritten off raw `this.$axios` calls that hard-coded
   * `https://shop.fixmypc.ie/` as their base URL and swallowed every failure
   * into console.error — so a refused request looked identical to an empty
   * catalogue, and the panel could not be pointed at another environment.
   * Everything goes through the shared common store actions now, which carry
   * the bearer token and the language, and raise a toast on failure.
   */
  export default {
    name: 'categories',
    middleware: ['common-middleware', 'auth'],
    mixins: [util],
    components: {
      ConfirmDialog
    },
    data() {
      return {
        // list state
        itemList: [],
        pagination: {},
        stats: {},
        loading: true,
        loaded: false,
        errorMessage: '',

        // query state — everything the server needs to reproduce this view
        limit: 25,
        search: '',
        orderby: 'display_order',
        ordertype: 'asc',
        filters: {
          status: '',
          scope: '',
          parent: '',
          has_products: ''
        },

        // ui state
        /* 'tree' groups every subcategory under its parent; 'flat' is the
         * plain sortable list. Restored from the last visit in mounted(). */
        view: 'tree',
        /* Collapsed parents, keyed by id as a string — ids arrive as strings
         * from the API and as numbers from nothing, but an object key is a
         * string either way, so everything that touches this normalises. */
        collapsed: {},
        showFilter: false,
        cbList: [],
        menuId: null,
        busyId: null,
        bulkWorking: false,
        exporting: false,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,

        // parent picker
        parentOptions: [],
        parentsLoading: false,

        // delete
        showDeleteConfirm: false,
        deleteTarget: null,
        deleteType: null,
        deleting: false,
        impact: null,
        impactLoading: false,
        childrenMode: 'reparent',

        // embed
        showEmbed: false,
        selectedCategory: null,
        embed: {
          layout: 'horizontal',
          mode: 'normal',
          limit: 3,
          border: 'none',
          fluid: true,
          height: '400px',
          width: '100%'
        },

        sortOptions: {
          display_order: 'Sort order',
          title: 'Name',
          parent: 'Parent category',
          products_count: 'Product count',
          children_count: 'Subcategory count',
          status: 'Status',
          created_at: 'Created date',
          updated_at: 'Updated date'
        },

        searchTimer: null
      }
    },
    computed: {
      hasRows() {
        return this.itemList.length > 0
      },
      /**
       * The rows the table actually draws.
       *
       * In the flat view that is simply the server's page. In the tree view
       * the server has already sent the branch in pre-order with a depth on
       * every row, so two things are left to decide here: what a collapsed
       * parent hides, and what to say at the end of a branch that is not all
       * there.
       *
       * That closing note covers two different facts, and they are not
       * interchangeable. A parent with no subcategories at all should say so
       * — a hierarchy that simply skips its empty parents leaves the reader
       * working out which bold line starts a new branch and which is a leaf.
       * A parent showing three of its five children because a filter took the
       * other two is a different thing entirely, and saying "No subcategories"
       * there would be untrue.
       */
      displayRows() {
        if (!this.isTree) return this.itemList

        const rows = []
        const hidden = {}

        // The root whose branch we are currently inside, so the note can be
        // appended after its last child rather than between it and them.
        let branch = null

        const closeBranch = () => {
          const cat = branch
          branch = null

          if (!cat || this.collapsed[String(cat.id)]) return

          const total = Number(cat.children_count || 0)
          const shown = Number(cat.visible_children || 0)

          // A complete, non-empty branch needs no note.
          if (total && total === shown) return

          rows.push({
            placeholder: true,
            key: 'placeholder-' + cat.id,
            parent: cat,
            hidden: Math.max(total - shown, 0)
          })
        }

        this.itemList.forEach(cat => {

          const parent = cat.parent === null || cat.parent === undefined
            ? null
            : String(cat.parent)

          /* Pre-order guarantees a parent is seen before its children, so one
           * pass hides a whole branch: a row is hidden if its parent is
           * collapsed or was itself hidden. */
          if (parent && (hidden[parent] || this.collapsed[parent])) {
            hidden[String(cat.id)] = true
            return
          }

          if (!this.rowDepth(cat)) {
            closeBranch()
            branch = cat
          }

          rows.push(cat)
        })

        closeBranch()

        return rows
      },
      /* Subcategories on this page — the figure the summary line quotes
       * alongside the parent count. */
      subRowCount() {
        return this.itemList.filter(cat => this.rowDepth(cat) > 0).length
      },
      /* Parents with something to collapse. Nothing to expand means the
       * expand/collapse control has nothing to act on and is disabled. */
      expandableCount() {
        return this.itemList.filter(cat => !this.rowDepth(cat) && this.branchSize(cat)).length
      },
      allExpanded() {
        return this.itemList.every(cat => !this.branchSize(cat) || !this.collapsed[String(cat.id)])
      },
      isTree() {
        return this.view === 'tree'
      },
      columnCount() {
        // checkbox + thumb + the columns on screen + actions
        return this.visibleColumns.length + 3
      },
      sortableColumns() {
        return [
          { key: 'title', label: 'Category', class: 'col-category' },
          { key: 'parent', label: 'Parent', class: 'col-parent' },
          { key: 'children_count', label: 'Subcats', class: 'col-count' },
          { key: 'products_count', label: 'Products', class: 'col-count' },
          { key: 'status', label: 'Status', class: 'col-status' },
          { key: 'display_order', label: 'Order', class: 'col-order' },
          { key: 'created_at', label: 'Created', class: 'col-date' },
          { key: 'updated_at', label: 'Updated', class: 'col-date' }
        ]
      },
      /* The tree already says what each row's parent is, far more directly
       * than a column repeating the name, so that column is dropped rather
       * than left to take width from the one that matters. */
      visibleColumns() {
        return this.isTree
          ? this.sortableColumns.filter(column => column.key !== 'parent')
          : this.sortableColumns
      },
      /* Skeleton rows match the page size, so the table does not jump height
       * when the real rows arrive. */
      skeletonRows() {
        const size = Number(this.limit) || 10
        return Math.min(size, 10)
      },
      /* Every figure comes from the server's stats block. Nothing here is
       * counted in the browser, and nothing is a placeholder. */
      summaryCards() {
        const s = this.stats || {}
        const growth = Number(s.growth || 0)

        return [
          {
            key: 'total', title: 'Total Categories', tone: 'blue',
            icon: 'fa fa-layer-group', value: this.statValue(s.total),
            badge: this.loaded ? this.formatGrowth(growth) : '',
            badgeClass: this.growthClass(growth)
          },
          {
            key: 'root', title: 'Root Categories', tone: 'purple',
            icon: 'fa fa-folder', value: this.statValue(s.root_categories)
          },
          {
            key: 'sub', title: 'Subcategories', tone: 'purple',
            icon: 'fa fa-sitemap', value: this.statValue(s.sub_categories)
          },
          {
            key: 'active', title: 'Published', tone: 'green',
            icon: 'fa-solid fa-circle-check', value: this.statValue(s.active_categories)
          },
          {
            key: 'inactive', title: 'Draft', tone: 'orange',
            icon: 'fa fa-clock', value: this.statValue(s.inactive_categories)
          },
          {
            key: 'with_products', title: 'With Products', tone: 'blue',
            icon: 'fa fa-box', value: this.statValue(s.with_products)
          }
        ]
      },
      activeFilterCount() {
        return Object.keys(this.filters)
          .filter(key => this.filters[key] !== '' && this.filters[key] !== null).length
      },
      /* Drives which empty state is shown: "nothing matches" reads very
       * differently from "you have no categories". */
      isFiltered() {
        return this.activeFilterCount > 0 || this.search.trim() !== ''
      },
      resultSummary() {
        if (this.loading) return 'Loading categories…'
        if (this.errorMessage) return 'Could not load categories'
        if (!this.pagination.total) {
          return this.isFiltered ? 'No categories match your filters' : 'No categories yet'
        }

        const { from, to, total } = this.pagination

        /* A page of the tree is a page of parents, not of rows — saying
         * "1–15 of 15" beside eighteen visible rows reads as a bug. */
        if (this.isTree) {
          const parents = `Showing ${from}–${to} of ${total} parent ${total === 1 ? 'category' : 'categories'}`
          const subs = this.subRowCount
          return subs
            ? `${parents}, ${subs} ${subs === 1 ? 'subcategory' : 'subcategories'}`
            : parents
        }

        return `Showing ${from}–${to} of ${total}`
      },
      /* Every category on screen now, in the order it is drawn: what the
       * select-all checkbox covers. Reading it off itemList instead would
       * silently include the children of a collapsed parent, so a bulk delete
       * could take rows the admin could not see. */
      pageIds() {
        return this.displayRows.filter(row => !row.placeholder).map(row => row.id)
      },
      allOnPageSelected() {
        return this.hasRows && this.pageIds.every(id => this.cbList.indexOf(id) > -1)
      },
      someOnPageSelected() {
        const picked = this.pageIds.filter(id => this.cbList.indexOf(id) > -1).length
        return picked > 0 && picked < this.pageIds.length
      },
      /* A windowed pager. The old one rendered one button per page, so a few
       * hundred categories produced a row of buttons wider than the screen. */
      pageWindow() {
        const last = Number(this.pagination.last_page) || 1
        const current = Number(this.pagination.current_page) || 1
        const pages = []
        const add = (value, gap) => pages.push({
          key: gap ? 'gap' + pages.length : value,
          value, label: gap ? '…' : String(value), gap: !!gap
        })

        if (last <= 7) {
          for (let i = 1; i <= last; i++) add(i)
          return pages
        }

        add(1)
        if (current > 4) add(null, true)

        const from = Math.max(2, current - 1)
        const to = Math.min(last - 1, current + 1)
        for (let i = from; i <= to; i++) add(i)

        if (current < last - 3) add(null, true)
        add(last)

        return pages
      },
      parentOfTargetLabel() {
        if (this.deleteType !== 'single' || !this.deleteTarget) return 'the top level'
        return this.deleteTarget.parent_title || 'the top level'
      },
      embedUrl() {
        if (!this.selectedCategory) return ''

        // The storefront base comes from configuration, not from a literal.
        const base = (process.env.CLIENT_BASE_URL || process.env.apiBase || '')
          .replace(/\/$/, '')

        const params = new URLSearchParams({
          layout: this.embed.layout,
          limit: this.embed.mode === 'all' ? 'all' : this.embed.limit,
          offers: this.embed.mode === 'offers' ? 1 : 0,
          product_border: this.embed.border === 'product' ? 1 : 0
        })

        return `${base}/embed/category/${this.selectedCategory.id}?${params.toString()}`
      },
      generatedCode() {
        if (!this.selectedCategory) return ''
        return `<iframe src="${this.embedUrl}" width="${this.embed.fluid ? '100%' : this.embed.width}" height="${this.embed.height}" style="border:0"></iframe>`
      }
    },
    methods: {
      // ---- reading --------------------------------------------------------

      /** Every parameter the current view depends on, in one place, so paging
       *  and refreshing cannot quietly drop a filter. */
      listParams(page) {
        const params = {
          page: page || 1,
          limit: this.limit,
          orderby: this.orderby,
          type: this.ordertype,
          time_zone: this.timezone,
          view: this.view
        }

        const term = this.search.trim()
        if (term) params.q = term

        Object.keys(this.filters).forEach(key => {
          const value = this.filters[key]
          if (value !== '' && value !== null) params[key] = value
        })

        return params
      },

      async fetchCategories(page = 1) {
        this.loading = true
        this.errorMessage = ''
        this.menuId = null

        try {
          const data = await this.getRequest({
            params: this.listParams(page),
            api: 'getCategories'
          })

          this.itemList = data.data || []
          this.stats = data.stats || {}

          const { data: rows, ...pagination } = data
          this.pagination = pagination

          /* A selection that is no longer on screen would be deleted
           * invisibly by a bulk action, so it is dropped on every load. */
          this.cbList = this.cbList.filter(id => this.pageIds.indexOf(id) > -1)

          this.loaded = true

        } catch (e) {
          this.itemList = []
          this.pagination = {}
          this.errorMessage = e?.message || this.$t('error.err') || 'Something went wrong'
          this.$store.dispatch('ui/setToastError', this.errorMessage)
        } finally {
          this.loading = false
        }
      },

      /** The parent filter and the delete dialog both need the tree. Uses the
       *  lightweight id/title endpoint rather than the full paginated list. */
      async fetchParentOptions() {
        this.parentsLoading = true
        try {
          const rows = await this.getRequest({
            params: { scope: 'all' },
            api: 'getAllCategories'
          })

          const list = Array.isArray(rows) ? rows : (rows?.data || [])
          const byParent = {}

          list.forEach(row => {
            const key = row.parent === null || row.parent === undefined ? 'root' : String(row.parent)
            if (!byParent[key]) byParent[key] = []
            byParent[key].push(row)
          })

          // Indented, so the dropdown reads as a tree rather than a flat list.
          const flatten = (key, depth) => {
            const out = []
            ;(byParent[key] || []).forEach(row => {
              out.push({
                id: row.id,
                label: (depth ? '— '.repeat(depth) : '') + row.title
              })
              out.push(...flatten(String(row.id), depth + 1))
            })
            return out
          }

          this.parentOptions = flatten('root', 0)

        } catch (e) {
          // The filter simply stays empty; the list itself still works.
          this.parentOptions = []
        } finally {
          this.parentsLoading = false
        }
      },

      // ---- query controls -------------------------------------------------

      /** Debounced, so typing a category name is one request rather than one
       *  per keystroke. */
      onSearchInput() {
        clearTimeout(this.searchTimer)
        this.searchTimer = setTimeout(() => this.fetchCategories(1), 350)
      },
      clearSearch() {
        this.search = ''
        this.fetchCategories(1)
      },
      applyFilters() {
        this.fetchCategories(1)
      },
      sortBy(field) {
        if (this.orderby === field) {
          this.ordertype = this.ordertype === 'asc' ? 'desc' : 'asc'
        } else {
          this.orderby = field
          this.ordertype = 'asc'
        }
        this.fetchCategories(1)
      },
      sortIcon(field) {
        if (this.orderby !== field) return 'fa fa-sort sort-idle'
        return this.ordertype === 'asc' ? 'fa fa-arrow-up' : 'fa fa-arrow-down'
      },
      ariaSort(field) {
        if (this.orderby !== field) return 'none'
        return this.ordertype === 'asc' ? 'ascending' : 'descending'
      },
      resetFilter() {
        this.search = ''
        this.orderby = 'display_order'
        this.ordertype = 'asc'
        this.filters = { status: '', scope: '', parent: '', has_products: '' }
        this.fetchCategories(1)
      },
      changePage(page) {
        if (page < 1 || page > (this.pagination.last_page || 1)) return
        if (page === this.pagination.current_page) return
        this.fetchCategories(page)
      },
      refresh() {
        this.fetchCategories(this.pagination.current_page || 1)
      },

      // ---- selection ------------------------------------------------------

      toggleSelectAll(event) {
        if (event.target.checked) {
          const merged = this.cbList.concat(this.pageIds)
          this.cbList = merged.filter((id, index) => merged.indexOf(id) === index)
        } else {
          this.cbList = this.cbList.filter(id => this.pageIds.indexOf(id) === -1)
        }
      },
      toggleMenu(cat) {
        this.menuId = this.menuId === cat.id ? null : cat.id
      },
      closeMenus() {
        this.menuId = null
      },

      // ---- writing --------------------------------------------------------

      /** Publish/unpublish. The row is only updated once the server has
       *  confirmed it, so a refused change never shows as applied. */
      async toggleStatus(cat) {
        if (this.busyId) return

        const next = Number(cat.status) === 1 ? 2 : 1
        this.busyId = cat.id

        try {
          await this.setRequest({
            params: { id: cat.id, status: next },
            api: 'setCategoryStatus'
          })

          // Confirmed: reflect it, and keep the summary cards honest.
          this.$set(cat, 'status', next)
          this.stats = {
            ...this.stats,
            active_categories: (this.stats.active_categories || 0) + (next === 1 ? 1 : -1),
            inactive_categories: (this.stats.inactive_categories || 0) + (next === 1 ? -1 : 1)
          }

        } catch (e) {
          this.$store.dispatch('ui/setToastError',
            e?.message || this.$t('error.err') || 'Could not change the status')
        } finally {
          this.busyId = null
        }
      },

      async bulkStatus(status) {
        if (!this.cbList.length || this.bulkWorking) return

        this.bulkWorking = true

        try {
          await this.setRequest({
            params: { id: this.cbList.join(','), status },
            api: 'setCategoryStatus'
          })

          this.cbList = []
          await this.fetchCategories(this.pagination.current_page || 1)

        } catch (e) {
          this.$store.dispatch('ui/setToastError',
            e?.message || this.$t('error.err') || 'Could not change the status')
        } finally {
          this.bulkWorking = false
        }
      },

      // ---- delete ---------------------------------------------------------

      async deleteNode(cat) {
        this.menuId = null
        this.deleteTarget = cat
        this.deleteType = 'single'
        this.childrenMode = 'reparent'
        this.showDeleteConfirm = true
        await this.loadImpact(cat.id)
      },
      async deleteBulk() {
        if (!this.cbList.length) return
        this.deleteTarget = [...this.cbList]
        this.deleteType = 'bulk'
        this.childrenMode = 'reparent'
        this.showDeleteConfirm = true
        await this.loadImpact(this.cbList.join(','))
      },
      /** What the delete would take with it, asked of the server rather than
       *  guessed from the row — the row only knows its own page. */
      async loadImpact(ids) {
        this.impact = null
        this.impactLoading = true

        try {
          this.impact = await this.getById({
            id: ids,
            params: {},
            api: 'getCategoryDeleteImpact'
          })
        } catch (e) {
          // The dialog still works; it just cannot warn about what is linked.
          this.impact = null
        } finally {
          this.impactLoading = false
        }
      },
      closeDelete() {
        if (this.deleting) return
        this.showDeleteConfirm = false
        this.deleteTarget = null
        this.impact = null
      },
      async executeDelete() {
        if (this.deleting) return

        this.deleting = true

        const ids = this.deleteType === 'bulk'
          ? this.deleteTarget.join(',')
          : this.deleteTarget.id

        try {
          /* The children mode rides as a query string: the shared delete
           * service puts its argument in the path, and a DELETE body is not
           * carried reliably by every proxy in front of this API. */
          await this.deleteData({
            params: `${ids}?children=${this.childrenMode}`,
            api: 'deleteCategory'
          })

          this.showDeleteConfirm = false
          this.deleteTarget = null
          this.impact = null
          this.cbList = []

          await this.fetchCategories(this.pagination.current_page || 1)
          this.fetchParentOptions()

        } catch (e) {
          /* The dialog stays open and the list is untouched — a failed delete
           * used to close the dialog and refresh, which read as success. */
          this.$store.dispatch('ui/setToastError',
            e?.message || this.$t('error.err') || 'Could not delete this category')
        } finally {
          this.deleting = false
        }
      },

      // ---- navigation and misc --------------------------------------------

      addCategory() {
        this.$router.push('/categories/add')
      },
      addChild(cat) {
        this.menuId = null
        this.$router.push({ path: '/categories/add', query: { parent: cat.id } })
      },
      editNode(node) {
        this.$router.push(`/categories/${node.id}`)
      },
      openEmbedModal(node) {
        this.menuId = null
        this.selectedCategory = node
        this.showEmbed = true
      },
      copyCode() {
        if (!navigator.clipboard) return
        navigator.clipboard.writeText(this.generatedCode).then(() => {
          this.$store.dispatch('ui/setToastMessage', 'Embed code copied')
        })
      },


      // ---- the hierarchy --------------------------------------------------

      /** Switching layout is a different question of the server, not a
       *  re-sort of what is already here, so it refetches from page one. */
      setView(view) {
        if (this.view === view) return

        this.view = view
        this.cbList = []

        /* Remembered, because an admin who prefers one layout prefers it every
         * time. Wrapped because storage throws outright in a locked-down
         * browser profile, and a layout preference is not worth a blank page. */
        try {
          window.localStorage.setItem('categories.view', view)
        } catch (e) {}

        this.fetchCategories(1)
      },
      rowDepth(cat) {
        return Number(cat.depth || 0)
      },
      /** How many of this parent's children are actually on this page. The
       *  server's own count of the branch, after the filters. */
      branchSize(cat) {
        return Number(cat.visible_children || 0)
      },
      isCollapsed(cat) {
        return !!this.collapsed[String(cat.id)]
      },
      /* A row that is only here to give a match somewhere below it a parent.
       * `matched` is only sent for the tree, and only a filter or a search can
       * produce a false, so an unfiltered list never shows the chip. */
      isContext(cat) {
        return this.isTree && cat.matched === false
      },
      /* The server decides what may take a child — the nesting cap lives with
       * the rest of the tree rules, not in two places. Older responses do not
       * carry the flag; a root could always take one. */
      canAddChild(cat) {
        return cat.can_have_children === undefined
          ? !this.isSub(cat)
          : !!cat.can_have_children
      },
      toggleBranch(cat) {
        if (!this.branchSize(cat)) return

        const key = String(cat.id)

        // $set/$delete: a plain assignment to a new key is not reactive in
        // Vue 2, so the first collapse of a given parent would do nothing.
        if (this.collapsed[key]) {
          this.$delete(this.collapsed, key)
        } else {
          this.$set(this.collapsed, key, true)
        }
      },
      expandAll() {
        this.collapsed = {}
      },
      collapseAll() {
        const next = {}

        this.itemList.forEach(cat => {
          if (this.branchSize(cat)) next[String(cat.id)] = true
        })

        this.collapsed = next
      },
      /**
       * The vertical guides to draw to the left of a row.
       *
       * ancestor_lines carries one flag per level above the row, true where
       * that ancestor still has siblings under it. The first entry describes
       * the root level, which is drawn as a plain heading row with no guide of
       * its own, so it is dropped here.
       */
      gutters(cat) {
        const lines = Array.isArray(cat.ancestor_lines) ? cat.ancestor_lines : []
        return lines.slice(1)
      },
      branchIcon(cat) {
        if (!this.branchSize(cat)) return 'fa fa-minus tree-toggle-none'
        return this.isCollapsed(cat) ? 'fa fa-chevron-right' : 'fa fa-chevron-down'
      },
      branchTitle(cat) {
        const size = this.branchSize(cat)

        if (!size) return cat.title + ' has no subcategories'

        return (this.isCollapsed(cat) ? 'Show the ' : 'Hide the ')
          + size + (size === 1 ? ' subcategory of ' : ' subcategories of ') + cat.title
      },
      treeIcon(cat) {
        if (this.rowDepth(cat)) return 'fa-regular fa-folder tree-icon-child'
        if (!this.branchSize(cat)) return 'fa-regular fa-folder-open tree-icon-empty'
        return this.isCollapsed(cat) ? 'fa-solid fa-folder' : 'fa-solid fa-folder-open'
      },
      rowClass(cat) {
        return {
          'is-sub': this.isSub(cat),
          'is-busy': this.busyId === cat.id,
          'is-parent-row': this.isTree && !this.rowDepth(cat),
          'is-child-row': this.isTree && this.rowDepth(cat) > 0,
          'is-context-row': this.isContext(cat)
        }
      },

      isSub(cat) {
        return !!cat.parent
      },
      statusIcon(cat) {
        if (this.busyId === cat.id) return 'fa fa-spinner fa-spin'
        return Number(cat.status) === 1 ? 'fa-solid fa-circle-check' : 'fa-regular fa-circle'
      },
      /* image_url is built by the server from its own media configuration, so
       * the panel does not have to know where uploads live. getImageURL is the
       * fallback for an older response shape. */
      rowImage(cat) {
        return cat.image_url || this.getImageURL(cat.image)
      },
      onImageError(event) {
        if (event.target.dataset.fallbackApplied) return
        event.target.dataset.fallbackApplied = '1'
        event.target.src = this.getImageURL(null)
      },
      statValue(value) {
        return value === undefined || value === null ? 0 : value
      },
      formatDate(date) {
        if (!date) return '—'
        const parsed = new Date(date)
        if (isNaN(parsed.getTime())) return '—'
        return parsed.toLocaleDateString('en-GB', {
          year: 'numeric', month: 'short', day: '2-digit'
        })
      },
      formatGrowth(value) {
        const n = Number(value) || 0
        return `${n > 0 ? '+' : ''}${n}%`
      },
      growthClass(value) {
        const n = Number(value) || 0
        if (n > 0) return 'is-up'
        if (n < 0) return 'is-down'
        return 'is-flat'
      },

      async exportCategories() {
        if (this.exporting) return
        this.exporting = true

        try {
          /* Exports what the admin is currently looking at — the same filters,
           * the same search, the same order — rather than a different set of
           * rows under the same button. */
          const data = await this.getRequest({
            params: { ...this.listParams(1), limit: 500 },
            api: 'getCategories'
          })

          const rows = data.data || []

          /* The tree view exports in hierarchy order, so Level says how deep
           * each row sits — without it the order looks arbitrary in a
           * spreadsheet that cannot show the indentation. */
          const header = ['Level', 'Name', 'Slug', 'Parent', 'Subcategories', 'Products', 'Status', 'Order', 'Created', 'Updated']

          const escape = (value) => {
            const s = value === null || value === undefined ? '' : String(value)
            return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
          }

          const csv = [header.join(',')].concat(rows.map(c => [
            this.isSub(c) ? 'Subcategory' : 'Root',
            c.title, c.slug, c.parent_title || '',
            c.children_count || 0, c.products_count || 0,
            Number(c.status) === 1 ? 'Published' : 'Draft',
            c.display_order,
            this.formatDate(c.created_at), this.formatDate(c.updated_at)
          ].map(escape).join(','))).join('\n')

          const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' })
          const url = URL.createObjectURL(blob)
          const link = document.createElement('a')
          link.href = url
          link.download = `categories-${new Date().toISOString().slice(0, 10)}.csv`
          document.body.appendChild(link)
          link.click()
          document.body.removeChild(link)
          URL.revokeObjectURL(url)

          this.$store.dispatch('ui/setToastMessage', `Exported ${rows.length} categories`)

        } catch (e) {
          this.$store.dispatch('ui/setToastError',
            e?.message || this.$t('error.err') || 'Could not export')
        } finally {
          this.exporting = false
        }
      },

      ...mapActions('common', ['getRequest', 'setRequest', 'deleteData', 'getById', 'emptyAllList'])
    },
    mounted() {
      if (!this.$can('category', 'view')) return

      /* The layout the admin last chose. Read before the first fetch so the
       * page does not load the flat list and then immediately reload the tree. */
      try {
        const saved = window.localStorage.getItem('categories.view')
        if (saved === 'tree' || saved === 'flat') this.view = saved
      } catch (e) {}

      this.fetchCategories()
      this.fetchParentOptions()

      document.addEventListener('click', this.closeMenus)
    },
    beforeDestroy() {
      clearTimeout(this.searchTimer)
      document.removeEventListener('click', this.closeMenus)
    }
  }
</script>

<style>
.container{
margin:0 auto;
}
/* page header area under top bar */
.breadcrumb {
font-size: 14px;
color: #111827;
display: flex;
align-items: center;
gap: 5px;
line-height: 20px;
font-weight: 600;
margin-bottom: 5px;
}
.breadcrumb span {
color: #9CA3AF;
font-weight: 400;
}
.breadcrumb i {
font-size: 10px;
color: #9CA3AF;
}
.page-title {
font-size: 30px;
font-weight: 800;
color: #111827;
line-height: 1.2em;
}
/* search row */
.page-actions{
display: flex;
gap: 15px;
justify-content: flex-end;
align-items: end;
}
button.btn.btn-filter {
  border: 1px solid #e5e7eb;
}
/* top actions */
.top-bar {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 20px;
    align-items: center;
    padding: 25px;
    top: 0;
    z-index: 9999;
    border-radius: 10px;
}
.top-bar .btn {
border: 0;
background: #fff;
border-radius: 50px;
cursor: pointer;
font-size: 14px;
font-weight: 500;
height: 44px;
transition: all 0.3s ease;
}
.top-bar .btn:hover {
background: #33319a !important;
color: #fff !important;
}
.search-box{
position:relative;
}
.page-actions .search-box input {
padding: 0 34px 0 38px;
border: 0;
border-radius: 50px;
width: 250px;
height: 44px;
line-height: 44px;
font-size: 14px;
font-weight: 400;
color: #111827;
background: #fff;
box-shadow: 0px 4px 20px -2px #0000000D;
}
.page-actions .search-box i {
position: absolute;
left: 15px;
top: 50%;
transform: translateY(-50%);
color: #9CA3AF;
font-size: 15px;
}
.top-bar .btn.black {
background: #000;
color: #fff;
font-weight: 700 !important;
}
.top-bar .btn i {
margin-right: 1px;
}
/* stat cards */
.cards .container{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:18px;
margin-bottom:25px;
}
.card {
background: #fff;
border-radius: 24px;
padding: 30px;
box-shadow: 0px 2px 8px 0px #0000000A;
box-shadow: 0px 0px 0px 1px #00000008;
}
.card-inr {
display: flex;
align-items: center;
justify-content: space-between;
}
.card-inr .card-prcn {
background: #EFF6FF;
border-radius: 50px;
display: flex;
align-items: center;
justify-content: center;
padding: 5px 9px;
font-weight: 700;
font-size: 12px;
color: #2563EB;
}
.card-inr .card-prcn.is-up {
background: #ECFDF5;
color: #059669;
}
.card-inr .card-prcn.is-down {
background: #FEF2F2;
color: #DC2626;
}
.card-inr .card-prcn.is-flat {
background: #F3F4F6;
color: #6B7280;
}
.table-actions .btn[disabled] {
opacity: .6;
cursor: not-allowed;
}
.card-inr.purple-col .card-prcn {
background: #FAF5FF;
color: #9333EA;
}
.card-inr.green-col .card-prcn {
background: #F0FDF4;
color: #16A34A;
}
.card-inr.orange-col .card-prcn {
background: #FFF7ED;
color: #EA580C;
}
.cart-cnt {
margin-top: 15px;
}
.icon-box {
width: 48px;
height: 48px;
display: flex;
align-items: center;
justify-content: center;
border-radius: 16px;
}
.blue {
background: #EFF6FF;
color: #2563EB;
}
.purple {
background: #FAF5FF;
color: #9333EA;
}
.green {
background: #F0FDF4;
color: #16A34A;
}
.orange {
background: #FFF7ED;
color: #EA580C;
}
.card .cart-cnt h4 {
font-size: 14px;
font-weight: 500;
color: #6B7280;
margin-bottom: 7px;
}
.card .cart-cnt h2 {
font-size: 30px;
font-weight: 700;
color: #111827;
}

.cel-inr span {
font-weight: 400;
font-size: 12px;
color: #6B7280;
}
.cel-inr {
display: flex;
flex-direction: column;
gap: 1.5px;
font-weight: 600;
font-size: 14px;
line-height: 20px;
color: #111827;
}

/* parent row */
.parent-cat {
  display: flex;
  align-items: center;
  gap: 12px;
}

.expand-icon,
.expand-placeholder{
width:16px;
display:flex;
align-items:center;
justify-content:center;
}

.expand-icon i{
cursor:pointer;
color:#9CA3AF;
transition:0.2s;
}

.expand-icon i:hover{
color:#111827;
}

/* smaller thumbnail */
.sub-row .category-thumb {
  width: 32px;
  height: 32px;
}

 /* table container */
.table-box .container {
background: #fff;
border-radius: 32px;
padding: 30px;
box-shadow: 0px 2px 8px 0px #0000000A;
box-shadow: 0px 0px 0px 1px #00000008;
}
.table-header{
display:flex;
justify-content:space-between;
align-items:center;
margin-bottom:15px;
}
.table-header h3 {
font-size: 18px;
font-weight: 700;
line-height: 1.2em;
color: #111827;
}
.table-header p {
font-size: 14px;
color: #6B7280;
line-height: 20px;
margin-top: 5px;
}
.table-actions{
display:flex;
align-items:center;
gap:12px;
}
.table-actions button {
margin-left: 8px;
color: #4B5563;
font-size: 14px;
font-weight: 500;
border: 1px solid #E5E7EB;
background: transparent;
border-radius: 50px;
transition: all 0.3s ease;
cursor: pointer;
}
.table-actions button:hover {
color: #fff !important;
border-color: #111827 !important;
background: #111827 !important;
}

.table-box th, .table-box td {
text-align:center;
}
.table-box thead tr th {
font-size: 14px;
}
.table-box .pagination {
  border: 0;
  border-top: 1px solid #e3e3e3;
  padding-top: 18px;
  margin-top: 30px;
  border-radius: 0 !important;
}
.category-cell{
display:flex;
align-items:center;
gap:10px;
}
.category-icon {
width: 48px;
height: 48px;
border-radius: 16px;
display: flex;
align-items: center;
justify-content: center;
color: #fff;
box-shadow: 0px 1px 2px 0px #0000000D;
}
.category-icon.blue {
background: linear-gradient(135deg, #60A5FA 0%, #2563EB 100%);
}
.category-icon.white {
border: 2px solid #E5E7EB;
color: #9CA3AF !important;
}
.category-icon.purple {
background: linear-gradient(135deg, #C084FC 0%, #9333EA 100%);
}
.category-icon.orange {
background: linear-gradient(135deg, #FB923C 0%, #EA580C 100%);
}
.badge {
padding: 5px 12px;
border-radius: 50px;
font-size: 12px;
font-weight: 600;
display: flex;
align-items: center;
justify-content: center;
gap: 4px;
width: fit-content;
}
.badge.green {
background: #F0FDF4;
color: #16A34A;
}
.badge.orange {
background: #FFF7ED;
color: #EA580C;
}
.badge i {
font-size: 6px;
}
.actions i {
margin-right: 22px;
cursor: pointer;
color: #9CA3AF;
}
.actions i:last-child {
margin-right: 0px !important;
}
/* pagination */
.pagination{
display:flex;
justify-content:space-between;
align-items:center;
margin-top:15px;
}
.pagination .shw-cat {
font-size: 14px;
font-weight: 400;
color: #6B7280;
}
.pagination .shw-cat span {
color: #111827;
font-weight: 500;
}
.page-buttons{
display:flex;
gap:6px;
}
.page-buttons span {
border: 1px solid #E5E7EB;
padding: 6px 10px;
border-radius: 8px;
cursor: pointer;
width: 36px;
height: 36px;
display: flex;
align-items: center;
justify-content: center;
font-size: 14px;
font-weight: 600;
color: #4B5563;
}
.page-buttons .active{
background:#000;
color:#fff;
border-color:#000;
}
.category-embed-modal {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(6px);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 9999;
  padding: 40px;
}

/* MODAL CONTAINER */
.embed-box {
  width: 1050px;
  background: #f8fafc;
  border-radius: 18px;
  box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25);
  overflow: hidden;
  animation: fadeIn .2s ease;
}

/* HEADER */
.embed-header {
  padding: 22px 28px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.embed-header h3 {
  font-size: 20px;
  font-weight: 600;
  color: #0f172a;
  margin: 0;
}

.close {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #0f172a;
  color: #fff;
  display: flex;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  font-size: 14px;
  transition: 0.2s;
}

.close:hover {
  background: #1e293b;
}

/* BODY LAYOUT */
.embed-body{
display:flex;
gap:28px;
padding:30px;
}

.embed-preview{
width:55%;
background:#fff;
border-radius:16px;
padding:16px;
box-shadow:0 2px 8px rgba(0,0,0,0.08);
display:flex;
justify-content:center;
align-items:center;
}

.embed-preview iframe{
width:100%;
height:350px;
border:none;
border-radius:10px;
}

/* LEFT PANEL */
.embed-left {
  width: 45%;
  background: #f1f5f9;
  border-radius: 16px;
  padding: 20px;
}

.embed-left label {
  display: block;
  font-size: 14px;
  font-weight: 500;
  margin-top: 15px;
  color: #334155;
}

.embed-left select {
    width: 100%;
}

.embed-left input:not([type="checkbox"]),
.embed-left select {
  margin-top: 6px;
  padding: 8px 10px;
  border-radius: 8px;
  border: 1px solid #cbd5e1;
  font-size: 14px;
  outline: none;
}

.embed-left input[type="checkbox"] {
  width: 16px;
  height: 16px;
  margin: 0;
  cursor: pointer;
}

/* RIGHT PANEL */
.embed-right {
  width: 75%;
}

/* CONFIGURATION BOX */
.config-box {
  background: #eef2f7;
  border-radius: 14px;
  padding: 22px;
  margin-bottom: 22px;
}

.config-box h4 {
  margin: 0 0 15px;
  font-weight: 600;
  color: #0f172a;
}

.config-box label {
  display: block;
  margin-bottom: 8px;
  font-size: 14px;
  color: #334155;
}

.size-inputs {
  display: flex;
  gap: 20px;
  margin-top: 14px;
}

.size-inputs input {
  width: 100%;
  padding: 8px 10px;
  border-radius: 8px;
  border: 1px solid #cbd5e1;
}

/* CODE SECTION */
.code-box {
  background: #0f172a;
  border-radius: 18px;
  padding: 22px;
  position: relative;
}

.code-box textarea {
  width: 100%;
  height: 140px;
  background: #1e293b;
  color: #e2e8f0;
  border: none;
  border-radius: 10px;
  padding: 16px;
  font-size: 13px;
  resize: none;
  outline: none;
}

/* COPY BUTTON */
.code-box button {
  margin-top: 16px;
  width: 46px;
  height: 46px;
  border-radius: 12px;
  border: none;
  background: #ffffff;
  cursor: pointer;
  font-weight: 600;
  transition: 0.2s;
}

.code-box button:hover {
  transform: scale(1.05);
}

.checkbox-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
}

.checkbox-row input {
  width: 16px;
  height: 16px;
  cursor: pointer;
}

.checkbox-row label {
  margin: 0;
  cursor: pointer;
}

.radio-group {
  margin-top: 10px;
}

.radio-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  font-size: 14px;
  cursor: pointer;
}

.radio-row input {
  width: 16px;
  height: 16px;
  cursor: pointer;
}

.category-thumb{
width:40px;
height:40px;
object-fit:cover;
border-radius:10px;
border:1px solid #eee;
}

/* Filter */
.filter-bar{
align-items: end;
display: flex;
gap: 15px;
}

.filter-item{
display:flex;
flex-direction:column;
gap:4px;
}

.filter-item label{
font-size:12px;
font-weight:600;
color:#6B7280;
}

.filter-item select{
padding:9px 12px;
border:1px solid #E5E7EB;
border-radius:8px;
font-size:14px;
min-width:140px;
background:#fff;
}

.apply-btn{
background: #000;
color: #fff;
font-weight: 700 !important;
}

.apply-btn:hover{
    background: #33319a !important;
    color: #fff !important;
}

.reset-btn{
display:flex;
align-items:center;
gap:6px;
background:#F3F4F6;
border:none;
padding:9px 16px;
border-radius:8px;
cursor:pointer;
font-size:14px;
font-weight:600;
color:#374151;
transition:all .2s ease;
}

.reset-btn i{
font-size:13px;
}

.reset-btn:hover{
background:#E5E7EB;
}

.limit-box{
display:flex;
align-items:center;
gap:6px;
font-size:14px;
color:#6B7280;
margin-right:10px;
}

.limit-box select{
border:1px solid #E5E7EB;
border-radius:8px;
padding:6px 10px;
font-size:14px;
background:#fff;
cursor:pointer;
}

/* Sub category row styling */
.sub-row {
  background: #f4f6f9;
}

.sub-row td {
  padding: 8px 10px;
  font-size: 13px;
}

.clickable-row{
cursor:pointer;
transition:0.15s;
}

.clickable-row:hover{
background:#f9fafb;
}

.sortable{
cursor:pointer;
user-select:none;
}

.sortable:hover{
color:#111827;
}

.sortable i{
margin-left:6px;
font-size:11px;
color:#6B7280;
}

.category-type {
    text-align: center;
    padding: 0px 5px;
    margin-top: 4px;
    letter-spacing: 0.2px;
    border-radius: 8px;
}

/* Primary */
.category-type.primary{
background:#EEF2FF;
color:#4338CA;
border:1px solid #E0E7FF;
}

/* Secondary */
.category-type.secondary{
background:#ECFDF5;
color:#047857;
border:1px solid #D1FAE5;
}


th.col-checkbox {
    width: 65px;
    padding: 12.5px 10px 12.5px 20px !important;
}
th.sortable.col-products {
    width: 100px;
}
th.sortable.col-status {
    width: 130px;
}
th.sortable.col-created {
    width: 155px;
}
th.col-actions {
    width: 180px;
}
th.sortable.col-parent, th.sortable.col-slug {
    width: 180px;
}
.category-cell.sb-cat {
    margin-left: 40px;
    position: relative;
}
th.sortable.col-category {
    width: 220px;
}

.parent-select{
border:1px solid #E5E7EB;
border-radius:8px;
padding:6px 10px;
font-size:13px;
background:#fff;
cursor:pointer;
min-width:140px;
}

.cards, .table-box, .filter-bar {
  margin-left: auto;
  margin-right: auto;
  border-radius: 10px;
}

.expand-checkbox{
  position: relative;
}

.expand-icon {
  position: absolute;
  left: 0px;
  top: 27px;
}

.table-box table {
    width: 100% !important;
    table-layout: auto;
    border-radius: 16px;
    overflow: hidden;
}

/* ANIMATION */
@keyframes fadeIn {
  from {
    opacity: 0;
    transform: scale(.97);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

@media only screen and (max-width:767px) {
 
.embed-body {
    flex-direction: column !important;
}
.embed-left, .embed-right {
    width: 100% !important;
}
}

@media only screen and (max-width:1200px) {
 
.category-embed-modal {
    overflow: scroll;
}
.embed-box {
    top: 90px;
    position: relative;
}
}

/* =====================================================================
   Category list — additions
   Same tokens as the cards and badges above; nothing new is introduced.
   ===================================================================== */

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.page-subtitle {
  font-size: 14px;
  color: #6B7280;
  margin-top: 6px;
  max-width: 52ch;
  line-height: 1.5;
}

/* ---- search ---------------------------------------------------------- */

.page-actions .search-box input[type="search"]::-webkit-search-cancel-button {
  display: none;
}

.search-clear {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  border: 0;
  background: transparent;
  color: #9CA3AF;
  cursor: pointer;
  font-size: 13px;
  padding: 4px;
  line-height: 1;
  border-radius: 50%;
}

.search-clear:hover {
  color: #111827;
  background: #F3F4F6;
}

/* ---- filters --------------------------------------------------------- */

.btn.btn-filter.is-on {
  background: #33319a;
  color: #fff;
  border-color: #33319a;
}

.filter-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  margin-left: 6px;
  border-radius: 50px;
  background: #fff;
  color: #33319a;
  font-size: 11px;
  font-weight: 700;
}

.filter-panel {
  background: #fff;
  border-radius: 20px;
  box-shadow: 0px 0px 0px 1px #00000008;
  padding: 20px 25px;
  margin-bottom: 20px;
}

.filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  align-items: end;
}

.filter-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}

.filter-item label {
  font-size: 12px;
  font-weight: 600;
  color: #6B7280;
  text-transform: uppercase;
  letter-spacing: .04em;
}

.filter-item select,
.limit-box select {
  height: 40px;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  padding: 0 10px;
  font-size: 14px;
  color: #111827;
  background: #fff;
  max-width: 100%;
}

.filter-item select:focus-visible,
.limit-box select:focus-visible,
.page-actions .search-box input:focus-visible {
  outline: 2px solid #33319a;
  outline-offset: 1px;
}

.filter-item select:disabled {
  background: #F9FAFB;
  color: #9CA3AF;
}

.filter-hint {
  font-size: 11px;
  color: #9CA3AF;
}

.filter-reset-cell {
  justify-content: flex-end;
}

.reset-btn {
  height: 40px;
  border: 1px solid #E5E7EB;
  background: #fff;
  border-radius: 10px;
  padding: 0 14px;
  font-size: 14px;
  font-weight: 500;
  color: #374151;
  cursor: pointer;
  white-space: nowrap;
}

.reset-btn:hover {
  background: #F9FAFB;
  border-color: #D1D5DB;
}

/* ---- summary cards --------------------------------------------------- */

.cards .container {
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
}

.stat-skeleton {
  display: block;
  height: 30px;
  width: 60px;
  border-radius: 8px;
  background: linear-gradient(90deg, #F3F4F6 25%, #E5E7EB 37%, #F3F4F6 63%);
  background-size: 400% 100%;
  animation: category-shimmer 1.4s ease infinite;
}

/* ---- bulk bar -------------------------------------------------------- */

.bulk-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  background: #EEF2FF;
  border: 1px solid #C7D2FE;
  border-radius: 14px;
  padding: 12px 16px;
  margin-bottom: 14px;
}

.bulk-count {
  font-size: 14px;
  color: #3730A3;
}

.bulk-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.bulk-btn {
  height: 36px;
  padding: 0 14px;
  border-radius: 50px;
  border: 1px solid #C7D2FE;
  background: #fff;
  font-size: 13px;
  font-weight: 600;
  color: #3730A3;
  cursor: pointer;
}

.bulk-btn:hover:not(:disabled) {
  background: #3730A3;
  color: #fff;
}

.bulk-btn.danger {
  color: #DC2626;
  border-color: #FECACA;
}

.bulk-btn.danger:hover:not(:disabled) {
  background: #DC2626;
  color: #fff;
}

.bulk-btn.ghost {
  border-color: transparent;
  color: #6B7280;
}

.bulk-btn:disabled {
  opacity: .55;
  cursor: not-allowed;
}

/* ---- table ----------------------------------------------------------- */

.table-scroll {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.category-table {
  width: 100%;
  min-width: 960px;
  border-collapse: collapse;
}

.category-table th {
  font-size: 12px;
  font-weight: 700;
  color: #6B7280;
  text-transform: uppercase;
  letter-spacing: .04em;
  text-align: left;
  padding: 12px 14px;
  border-bottom: 1px solid #E5E7EB;
  white-space: nowrap;
}

.category-table th.sortable {
  cursor: pointer;
  user-select: none;
}

.category-table th.sortable:hover {
  color: #111827;
}

.category-table th.sortable:focus-visible {
  outline: 2px solid #33319a;
  outline-offset: -2px;
  border-radius: 6px;
}

.category-table th i {
  margin-left: 6px;
  font-size: 11px;
}

.category-table th i.sort-idle {
  opacity: .3;
}

.category-table td {
  padding: 12px 14px;
  border-bottom: 1px solid #F3F4F6;
  font-size: 14px;
  color: #374151;
  vertical-align: middle;
}

.category-table tbody tr.clickable-row {
  cursor: pointer;
  transition: background .15s ease;
}

.category-table tbody tr.clickable-row:hover {
  background: #F9FAFB;
}

.category-table tbody tr.clickable-row:focus-visible {
  outline: 2px solid #33319a;
  outline-offset: -2px;
}

.category-table tbody tr.is-busy {
  opacity: .6;
  pointer-events: none;
}

.col-checkbox { width: 44px; }
.col-thumb { width: 64px; }
.col-count { width: 90px; }
.col-order { width: 80px; }
.col-status { width: 130px; }
.col-date { width: 120px; }
.col-actions { width: 96px; }

.thumb-frame {
  display: inline-flex;
  width: 44px;
  height: 44px;
  border-radius: 10px;
  overflow: hidden;
  background: #F3F4F6;
  align-items: center;
  justify-content: center;
  flex: none;
}

.category-thumb {
  width: 44px;
  height: 44px;
  object-fit: cover;
  display: block;
}

.category-cell {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.category-cell .cel-inr {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.cat-name {
  font-weight: 600;
  color: #111827;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cat-slug {
  font-size: 12px;
  color: #9CA3AF;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.category-type {
  font-size: 11px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 50px;
  white-space: nowrap;
  flex: none;
}

.category-type.primary { background: #EFF6FF; color: #2563EB; }
.category-type.secondary { background: #FAF5FF; color: #9333EA; }

.parent-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #4B5563;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.parent-chip i { font-size: 10px; color: #9CA3AF; }

.muted { color: #9CA3AF; }

.count-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 30px;
  height: 24px;
  padding: 0 8px;
  border-radius: 50px;
  background: #EEF2FF;
  color: #3730A3;
  font-size: 13px;
  font-weight: 600;
}

.count-chip.zero {
  background: #F3F4F6;
  color: #9CA3AF;
}

.badge-btn {
  border: 0;
  cursor: pointer;
  font: inherit;
}

.badge-btn:disabled { cursor: wait; }

.badge-btn:focus-visible {
  outline: 2px solid #33319a;
  outline-offset: 1px;
}

/* ---- row actions ----------------------------------------------------- */

.action-menu {
  position: relative;
  display: flex;
  gap: 4px;
  justify-content: flex-end;
}

.icon-btn {
  width: 32px;
  height: 32px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #6B7280;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.icon-btn:hover { background: #F3F4F6; color: #111827; }

.icon-btn:focus-visible {
  outline: 2px solid #33319a;
  outline-offset: 1px;
}

.action-dropdown {
  position: absolute;
  right: 0;
  top: 36px;
  z-index: 40;
  min-width: 190px;
  background: #fff;
  border: 1px solid #E5E7EB;
  border-radius: 12px;
  box-shadow: 0 12px 28px -8px rgba(17, 24, 39, .18);
  padding: 6px;
  display: flex;
  flex-direction: column;
}

.action-dropdown button {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 0;
  background: transparent;
  padding: 9px 10px;
  border-radius: 8px;
  font-size: 14px;
  color: #374151;
  cursor: pointer;
  text-align: left;
}

.action-dropdown button:hover { background: #F3F4F6; }
.action-dropdown button.danger { color: #DC2626; }
.action-dropdown button.danger:hover { background: #FEF2F2; }
.action-dropdown button i { width: 14px; font-size: 12px; }

/* ---- skeletons ------------------------------------------------------- */

@keyframes category-shimmer {
  0% { background-position: 100% 50%; }
  100% { background-position: 0 50%; }
}

.sk {
  display: inline-block;
  border-radius: 6px;
  background: linear-gradient(90deg, #F3F4F6 25%, #E5E7EB 37%, #F3F4F6 63%);
  background-size: 400% 100%;
  animation: category-shimmer 1.4s ease infinite;
}

.sk-line { height: 12px; }
.sk-line.sk-sm { height: 9px; margin-top: 6px; }
.sk-box { width: 16px; height: 16px; border-radius: 4px; }
.sk-thumb { width: 44px; height: 44px; border-radius: 10px; }
.sk-pill { width: 84px; height: 24px; border-radius: 50px; }

.w-30 { width: 30%; min-width: 34px; }
.w-40 { width: 40%; min-width: 44px; }
.w-60 { width: 60%; min-width: 60px; }
.w-70 { width: 70%; min-width: 80px; }

.skeleton-row td { height: 68px; }

/* ---- empty / error states ------------------------------------------- */

.state-box {
  padding: 48px 24px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.state-icon {
  width: 56px;
  height: 56px;
  border-radius: 18px;
  background: #F3F4F6;
  color: #6B7280;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
}

.state-icon.danger { background: #FEF2F2; color: #DC2626; }

.state-box h4 {
  font-size: 17px;
  font-weight: 700;
  color: #111827;
}

.state-box p {
  font-size: 14px;
  color: #6B7280;
  max-width: 46ch;
  line-height: 1.55;
}

.state-box .primary-btn { margin-top: 6px; }

.error-state { border-radius: 14px; background: #fff; }

/* ---- pagination ------------------------------------------------------ */

.page-buttons {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.page-btn {
  min-width: 36px;
  height: 36px;
  padding: 0 10px;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  background: #fff;
  color: #374151;
  font-size: 14px;
  cursor: pointer;
}

.page-btn:hover:not(:disabled):not(.active) { background: #F3F4F6; }

.page-btn.active {
  background: #33319a;
  border-color: #33319a;
  color: #fff;
  font-weight: 600;
}

.page-btn:disabled { opacity: .45; cursor: default; }
.page-btn.gap { border: 0; background: transparent; }

.page-btn:focus-visible {
  outline: 2px solid #33319a;
  outline-offset: 1px;
}

/* ---- delete dialog --------------------------------------------------- */

/* What the delete would affect. Passed into ConfirmDialog's slot, so it is
   styled here: Vue scopes slot content to the page, not to the component.
   Kept identical to the copy on the category form - the two dialogs are one
   component now and must not start drifting again through their slots. */

.impact-box {
  background: #F9FAFB;
  border: 1px solid #F1F3F5;
  border-radius: 12px;
  padding: 14px 16px;
  margin: 0 0 14px;
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.impact-line {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  font-size: 13px;
  line-height: 1.5;
  color: #374151;
}

.impact-line i { margin-top: 3px; font-size: 12px; flex: 0 0 auto; }
.impact-line.warn i { color: #EA580C; }
.impact-line.ok i { color: #16A34A; }
.impact-line strong { font-weight: 600; color: #111827; }

.children-choice {
  border: 1px solid #E5E7EB;
  border-radius: 12px;
  padding: 12px 14px;
  margin: 0 0 14px;
  text-align: left;
}

.children-choice legend {
  font-size: 12px;
  font-weight: 700;
  color: #6B7280;
  text-transform: uppercase;
  letter-spacing: .04em;
  padding: 0 6px;
}

.children-choice label {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 8px 4px;
  cursor: pointer;
}

.children-choice label span {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.children-choice strong { font-size: 14px; color: #111827; font-weight: 600; }
.children-choice em { font-style: normal; font-size: 12px; color: #6B7280; }



.embed-code {
  width: 100%;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12px;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  padding: 10px;
  resize: vertical;
}


/* =====================================================================
   Hierarchy
   The tree is drawn in one gutter to the left of the category name: a
   fixed-width slot per level, an elbow into the row, and a chevron on
   the parents. Guides are borders on empty spans rather than box glyphs
   so they line up at any row height and never fall out of the font.
   ===================================================================== */

.tree-truncated {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-top: 6px;
  max-width: 520px;
  font-size: 12px;
  color: #92400E;
}

.tree-truncated i { color: #D97706; }

.view-toggle {
  display: inline-flex;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  overflow: hidden;
  background: #fff;
}

/*
 * Scoped to .table-actions and marked !important on the hover states, because
 * the actions row already carries
 *   .table-actions button:hover { background: #111827 !important }
 * for Export and Refresh. The segmented control shares that container but is
 * not one of those buttons, and without this both tabs turn solid black on
 * hover. Naming the container here also beats .table-actions button outright
 * rather than winning a tie on source order.
 */
.table-actions .view-toggle button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: 0;
  background: transparent;
  padding: 0 14px;
  height: 38px;
  font-size: 13px;
  font-weight: 600;
  color: #6B7280;
  cursor: pointer;
  /*
   * Both reset .table-actions button, which styles Export and Refresh as
   * separated pills. Inherited here they broke the control apart: the
   * selected half rendered as its own rounded pill with an 8px gap beside
   * it, instead of filling its segment. The rounding belongs to the
   * container, which clips the segments with overflow: hidden.
   */
  margin-left: 0;
  border-radius: 0;
}

.table-actions .view-toggle button + button { border-left: 1px solid #E5E7EB; }
.table-actions .view-toggle button:hover:enabled {
  background: #F9FAFB !important;
  color: #111827 !important;
}

.table-actions .view-toggle button.on {
  background: #33319a;
  color: #fff;
}

.table-actions .view-toggle button.on:hover:enabled {
  background: #2a2880 !important;
  color: #fff !important;
}
.table-actions .view-toggle button:focus-visible { outline: 2px solid #33319a; outline-offset: -2px; }

/* The gutter: one slot per level, then the elbow. */
.tree-guide {
  display: inline-flex;
  align-self: stretch;
  align-items: stretch;
  flex: none;
  /*
   * Cancels the cell's own vertical padding, so the guide runs the full
   * height of the row and meets the one above it. Without this the line is
   * broken by 12px of padding at every row boundary and the branch reads as
   * a column of disconnected ticks.
   */
  margin: -12px 0;
}

/*
 * One level of nesting, as a width. Everything in the gutter is a multiple
 * of it, so a child's name lands far enough right of its parent's to read
 * as nested at a glance rather than as a slightly ragged column.
 */
.tree-gutter {
  width: 56px;
  flex: none;
}

.tree-gutter.has-line {
  border-left: 1px solid #E5E7EB;
  margin-left: 12px;
  width: 44px;
}

/*
 * The elbow. The vertical drops from under the parent's disclosure control,
 * which is what makes it read as coming out of that row; it runs the full
 * height for a middle child and stops at the arm for the last one, so the
 * end of a branch is visible without counting rows.
 */
.tree-elbow {
  position: relative;
  width: 44px;
  flex: none;
  margin-left: 12px;
}

.tree-elbow::before {
  content: "";
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  border-left: 1px solid #D1D5DB;
}

.tree-elbow.last::before { bottom: 50%; }

.tree-elbow::after {
  content: "";
  position: absolute;
  left: 0;
  top: 50%;
  width: 28px;
  border-top: 1px solid #D1D5DB;
}

.tree-toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  flex: none;
  border: 1px solid #E5E7EB;
  border-radius: 7px;
  background: #fff;
  color: #4B5563;
  font-size: 11px;
  cursor: pointer;
  transition: background .15s ease, border-color .15s ease;
}

.tree-toggle:hover:not(:disabled) {
  background: #EEF2FF;
  border-color: #C7D2FE;
  color: #33319a;
}

.tree-toggle:focus-visible { outline: 2px solid #33319a; outline-offset: 1px; }

/* A parent with nothing to open keeps the slot, so every name in the
   column still starts on the same vertical line. */
button.tree-toggle.empty,
button.tree-toggle.empty:hover {
  border-color: transparent;
  background: transparent;
  color: #E5E7EB;
  cursor: default;
}

.tree-icon {
  font-size: 14px;
  color: #33319a;
  flex: none;
  width: 16px;
  text-align: center;
}

.tree-icon-child { color: #9CA3AF; font-size: 13px; }
.tree-icon-empty { color: #D1D5DB; }

.category-table tbody tr.is-parent-row .cat-name {
  font-weight: 700;
  color: #111827;
}

.category-table tbody tr.is-child-row .cat-name {
  font-weight: 500;
  color: #374151;
}

/* A faint tint down the child rows, so a branch reads as one block even
   where the guide is interrupted by a row's hover state. */
.category-table tbody tr.is-child-row { background: #FCFCFD; }
.category-table tbody tr.is-child-row:hover { background: #F5F6FA; }

.branch-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 50px;
  background: #EEF2FF;
  color: #3730A3;
  font-size: 11px;
  font-weight: 700;
  flex: none;
}

.context-chip {
  font-size: 11px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 50px;
  background: #F3F4F6;
  color: #6B7280;
  white-space: nowrap;
  flex: none;
}

/* The "no subcategories" line. Deliberately quiet: it is the absence of
   something, and should not compete with the rows that are there. */
.category-table tbody tr.tree-placeholder-row { background: #FCFCFD; }
.category-table tbody tr.tree-placeholder-row:hover { background: #FCFCFD; }

/* The row is short on purpose: it marks the end of a branch, it is not a
   row of data, and at full height it doubled the table for nothing. */
.category-table tbody tr.tree-placeholder-row td {
  padding-top: 0;
  padding-bottom: 0;
}

.tree-placeholder-inner {
  display: flex;
  align-items: stretch;
  height: 30px;
}

/* The guide in this row is the only one that does not cancel the cell's
   padding, because there is none left to cancel. */
.tree-placeholder-inner .tree-guide { margin: 0; }

.tree-placeholder-inner .tree-placeholder-text,
.tree-placeholder-inner .tree-add-btn { align-self: center; }

.tree-placeholder-row .tree-elbow::before,
.tree-placeholder-row .tree-elbow::after { border-color: #E5E7EB; }

.tree-placeholder-text {
  font-size: 12px;
  font-style: italic;
  color: #9CA3AF;
  margin-left: 4px;
}

.tree-add-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-left: 10px;
  padding: 3px 9px;
  border: 1px dashed #D1D5DB;
  border-radius: 50px;
  background: transparent;
  color: #6B7280;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
}

button.tree-add-btn:hover:enabled {
  border-color: #33319a;
  border-style: solid;
  color: #33319a;
  background: #EEF2FF;
}

.tree-add-btn:focus-visible { outline: 2px solid #33319a; outline-offset: 1px; }
/* =====================================================================
   Responsive
   The table keeps its own horizontal scroller down to tablet; below that
   it is rebuilt as stacked cards, because a 960px-wide table squeezed on
   to a phone is not a table any more.
   ===================================================================== */

@media (max-width: 1100px) {
  .top-bar { flex-direction: column; align-items: stretch; }
  .page-actions { justify-content: flex-start; flex-wrap: wrap; }
  .search-box { flex: 1 1 220px; }
  .page-actions .search-box input { width: 100%; }
}

@media (max-width: 900px) {
  .table-header { flex-direction: column; align-items: flex-start; gap: 12px; }
  .table-actions { flex-wrap: wrap; }
}

@media (max-width: 760px) {
  .top-bar { padding: 18px; }
  .page-title { font-size: 24px; }
  .cards .container { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
  .card { padding: 20px; border-radius: 18px; }
  .card .cart-cnt h2 { font-size: 24px; }

  .table-scroll { overflow-x: visible; }
  .category-table { min-width: 0; }
  .category-table thead { display: none; }

  .category-table tbody tr {
    display: grid;
    grid-template-columns: 44px 1fr auto;
    grid-template-areas:
      "check thumb actions"
      "name  name  name"
      "meta  meta  meta";
    gap: 8px 10px;
    padding: 14px;
    margin-bottom: 12px;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    align-items: center;
  }

  .category-table tbody tr.skeleton-row { grid-template-areas: none; }

  .category-table td {
    border: 0;
    padding: 0;
  }

  .category-table td.col-checkbox { grid-area: check; }
  .category-table td.col-thumb { grid-area: thumb; }
  .category-table td.col-actions { grid-area: actions; }
  .category-table td.col-category { grid-area: name; }

  /* The remaining cells flow into one labelled strip under the name. */
  .category-table td.col-parent,
  .category-table td.col-count,
  .category-table td.col-status,
  .category-table td.col-order,
  .category-table td.col-date {
    grid-area: auto;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #6B7280;
  }

  .category-table td.col-date:last-of-type { display: none; }

  /*
   * On a phone the rows are cards, not a table, so a guide drawn from the
   * row above has nothing to connect to. The hierarchy is carried by the
   * card's own indent and left edge instead.
   */
  .tree-guide { display: none; }

  .category-table tbody tr.is-child-row {
    margin-left: 22px;
    border-left: 3px solid #C7D2FE;
  }

  .category-table tbody tr.tree-placeholder-row {
    margin-left: 22px;
    border-left: 3px solid #E5E7EB;
    display: block;
    padding: 10px 14px;
  }

  .tree-placeholder-row .col-checkbox,
  .tree-placeholder-row .col-thumb { display: none; }

  .tree-placeholder-cell { height: auto; }

  .view-toggle { flex: 1 1 auto; }
  .table-actions .view-toggle button { flex: 1 1 auto; justify-content: center; }

  /* Touch targets. */
  .icon-btn { width: 40px; height: 40px; }
  .badge-btn { min-height: 32px; }
  .bulk-bar { flex-direction: column; align-items: stretch; }
  .bulk-actions { justify-content: stretch; }
  .bulk-btn { flex: 1 1 auto; min-height: 40px; }
  .filter-grid { grid-template-columns: 1fr 1fr; }
  .pagination { flex-direction: column; gap: 12px; align-items: flex-start; }
}

@media (max-width: 480px) {
  .filter-grid { grid-template-columns: 1fr; }
  .page-actions .btn { flex: 1 1 auto; justify-content: center; }
  .page-btn { min-width: 40px; height: 40px; }
}

@media (prefers-reduced-motion: reduce) {
  .sk, .stat-skeleton { animation: none; }
  .category-table tbody tr.clickable-row { transition: none; }
}
</style>
