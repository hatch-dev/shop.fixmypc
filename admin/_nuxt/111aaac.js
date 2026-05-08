(window.webpackJsonp = window.webpackJsonp || []).push([
  [165],
  {
    949: function(t, e, r) {
      "use strict";
      r.r(e);
      
      // Configuration
      const config = {
        maxWaitTime: 5000,
        checkInterval: 200,
        containerClass: 'template-container',
        apiBaseUrl: '/api/templates',
        templatePageUrl: '/admin/templates',
        templatePageUrls: [
          '/templates',
          '/templates/',
          '/templates/index',
          '/templates/index.html'
        ]
      };

      // Track style elements
      let visibleStyle = null;
      let hiddenStyle = null;
      let currentVisibility = null;

      function createStyleElements() {
        if (!visibleStyle) {
          visibleStyle = document.createElement('style');
          visibleStyle.id = 'template-container-visible';
          visibleStyle.textContent = `.${config.containerClass} { display: block !important; }`;
        }
        if (!hiddenStyle) {
          hiddenStyle = document.createElement('style');
          hiddenStyle.id = 'template-container-hidden';
          hiddenStyle.textContent = `.${config.containerClass} { display: none !important; }`;
        }
      }

      function normalizePath(path) {
        return path.replace(/\/+$/, '').toLowerCase();
      }

      function updateContainerVisibility() {
        const isTemplatePage = normalizePath(window.location.pathname.toLowerCase()) === normalizePath(config.templatePageUrl.toLowerCase());

        if (isTemplatePage && currentVisibility != 'visible') {
          if (hiddenStyle && hiddenStyle.parentNode) {
            document.head.removeChild(hiddenStyle);
          }
          document.head.appendChild(visibleStyle);
          currentVisibility = 'visible';
        } 
        else if (!isTemplatePage && currentVisibility !== 'hidden') {
          if (visibleStyle && visibleStyle.parentNode) {
            document.head.removeChild(visibleStyle);
          }
          document.head.appendChild(hiddenStyle);
          currentVisibility = 'hidden';
        }
      }

      function isTemplatePage() {
        const path = window.location.pathname.toLowerCase();
        return config.templatePageUrls.some(url => path.endsWith(url));
      }

      // Main Template Manager Class
      class TemplateManager {
        constructor() {
          this.templates = [];
          this.currentSort = { field: 'created_at', direction: 'desc' };
          this.editor = null; // Jodit editor instance
          this.initContainer();
          this.initUI();
          this.loadTemplates();
        }

        initContainer() {
          this.container = document.createElement('div');
          this.container.className = 'template-container';
          document.body.appendChild(this.container);
          
          this.container.innerHTML = `
            <div class="loading-message">Loading templates...</div>
          `;
        }

        initUI() {
          const head = document.head || document.getElementsByTagName('head')[0];

          // Load Jodit CSS
          const joditCss = document.createElement('link');
          joditCss.rel = 'stylesheet';
          joditCss.href = 'https://cdn.jsdelivr.net/npm/jodit@3.24.5/build/jodit.min.css';
          head.appendChild(joditCss);

          // Create main container structure
          this.container.innerHTML = `
            <div class="template-manager">
              <div class="template-header">
                <div class="template-controls">
                  <div class="sort-controls">
                    <span class="lite-bold mr-0 hide-xxs">Order By</span>
                    <select class="sort-field">
                      <option value="created_at">Date</option>
                      <option value="name">Name</option>
                      <option value="updated_at">Last Updated</option>
                    </select>
                    <select class="sort-direction">
                      <option value="desc">Desc</option>
                      <option value="asc">Asc</option>
                    </select>
                  </div>
                  <div class="search-box">
                    <input type="text" placeholder="Search here" class="search-input">
                    <button class="search-btn primary-btn">Search</button>
                  </div>
                  <button class="add-template-btn button primary-btn">Add Template</button>
                </div>
              </div>
              <div class="template-table-container">
                <table class="template-table">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <!---<th>Content Preview</th>-->
                      <th>Created</th>
                      <th>Last Updated</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody class="template-table-body"></tbody>
                </table>
                <div class="loading-message">Loading templates...</div>
                <div class="error-message" style="display:none;"></div>
              </div>
            </div>
            <div class="template-form" style="display:none;">
              <h3 class="form-title">Add New Template</h3>
              <div class="form-group">
                <label>Template Name</label>
                <input type="text" class="template-name-input">
              </div>
              <div class="form-group">
                <label>Template Content</label>
                <textarea id="template-editor"></textarea>
              </div>
              <div class="form-actions">
                <button class="save-template-btn primary-btn">Save</button>
                <button class="cancel-form-btn primary-btn">Cancel</button>
              </div>
            </div>
          `;

          // Load Jodit JS and initialize editor
          const joditScript = document.createElement('script');
          joditScript.src = 'https://cdn.jsdelivr.net/npm/jodit@3.24.5/build/jodit.min.js';
          joditScript.onload = () => {
            this.initEditor();
            this.setupEventListeners();
          };
          joditScript.onerror = () => {
            console.error('Failed to load Jodit editor');
            this.showError('Failed to load editor. Please refresh the page.');
          };
          head.appendChild(joditScript);

          this.applyStyles();
        }

        initEditor() {
          // Destroy previous editor instance if exists
          if (this.editor && this.editor.destruct) {
            this.editor.destruct();
          }
          
          // Initialize new editor
          this.editor = new Jodit('#template-editor', {
            height: 300,
            toolbarAdaptive: false,
            toolbarSticky: false,
            showXPathInStatusbar: false,
            buttons: [
              'source', '|',
              'bold', 'italic', 'underline', 'strikethrough', '|',
              'ul', 'ol', 'outdent', 'indent', '|',
              'font', 'fontsize', 'brush', 'paragraph', '|',
              'align', 'undo', 'redo', '|',
              'hr', 'table', 'link', 'image', 'video', 'file', '|',
              'fullsize', 'print', 'about'
            ]
          });
        }

        setupEventListeners() {
          // Search
          this.container.querySelector('.search-btn').addEventListener('click', () => {
            this.searchTemplates(this.container.querySelector('.search-input').value);
          });

          // Sort controls
          this.container.querySelector('.sort-field').addEventListener('change', (e) => {
            this.currentSort.field = e.target.value;
            this.sortTemplates();
          });

          this.container.querySelector('.sort-direction').addEventListener('change', (e) => {
            this.currentSort.direction = e.target.value;
            this.sortTemplates();
          });

          // Add template button
          this.container.querySelector('.add-template-btn').addEventListener('click', () => {
            this.showTemplateForm();
          });

          // Form buttons
          this.container.querySelector('.save-template-btn').addEventListener('click', () => {
            this.saveTemplate();
          });

          this.container.querySelector('.cancel-form-btn').addEventListener('click', () => {
            this.hideTemplateForm();
          });
        }

        async loadTemplates() {
          try {
            this.showLoading();
            const response = await fetch(config.apiBaseUrl);
            if (!response.ok) throw new Error('Failed to load templates');
            
            this.templates = await response.json();
            this.renderTemplates();
          } catch (error) {
            console.error('Error loading templates:', error);
            this.showError('Failed to load templates. Please try again.');
          }
        }

        renderTemplates() {
          const tbody = this.container.querySelector('.template-table-body');
          tbody.innerHTML = '';

          if (this.templates.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="no-templates">No templates found</td></tr>';
            return;
          }

          this.templates.forEach(template => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td>${template.name || 'Untitled Template'}</td>
              <!--<td>${template.content ? template.content.substring(0, 50) + (template.content.length > 50 ? '...' : '') : ''}</td>-->
              <td>${new Date(template.created_at).toLocaleDateString()}</td>
              <td>${new Date(template.updated_at).toLocaleDateString()}</td>
              <td class="actions">
                <button class="edit-btn lite-btn" data-id="${template.id}">Edit</button>
                <button class="delete-btn lite-btn" data-id="${template.id}">Delete</button>
              </td>
            `;

            tr.querySelector('.edit-btn').addEventListener('click', () => {
              this.editTemplate(template.id);
            });

            tr.querySelector('.delete-btn').addEventListener('click', () => {
              if (confirm('Are you sure you want to delete this template?')) {
                this.deleteTemplate(template.id);
              }
            });

            tbody.appendChild(tr);
          });

          this.hideLoading();
        }

        sortTemplates() {
          this.templates.sort((a, b) => {
            const field = this.currentSort.field;
            const direction = this.currentSort.direction === 'asc' ? 1 : -1;
            
            if (field.includes('_at')) {
              return (new Date(a[field]) - new Date(b[field])) * direction;
            }
            
            return String(a[field]).localeCompare(String(b[field])) * direction;
          });
          
          this.renderTemplates();
        }

        searchTemplates(query) {
          if (!query) {
            this.renderTemplates();
            return;
          }
          
          const filtered = this.templates.filter(template => 
            template.name.toLowerCase().includes(query.toLowerCase()) || 
            (template.content && template.content.toLowerCase().includes(query.toLowerCase()))
          );
          
          const originalTemplates = this.templates;
          this.templates = filtered;
          this.renderTemplates();
          this.templates = originalTemplates;
        }

        showTemplateForm(template = null) {
          const form = this.container.querySelector('.template-form');
          const title = form.querySelector('.form-title');
          const nameInput = form.querySelector('.template-name-input');
          
          if (template) {
            title.textContent = 'Edit Template';
            nameInput.value = template.name || '';
            
            // Set editor content after ensuring editor is ready
            setTimeout(() => {
              if (this.editor) {
                this.editor.value = template.content || '';
              }
            }, 50);
            
            this.currentTemplateId = template.id;
          } else {
            title.textContent = 'Add New Template';
            nameInput.value = '';
            if (this.editor) {
              this.editor.value = '';
            }
            this.currentTemplateId = null;
          }
          
          form.style.display = 'block';
          form.scrollIntoView({ behavior: 'smooth' });
        }

        hideTemplateForm() {
          this.container.querySelector('.template-form').style.display = 'none';
          this.currentTemplateId = null;
          if (this.editor) {
            this.editor.value = '';
          }
        }

        async saveTemplate() {
          const form = this.container.querySelector('.template-form');
          const name = form.querySelector('.template-name-input').value.trim();
          const content = this.editor ? this.editor.value.trim() : '';
          
          if (!name) {
            alert('Please enter a template name');
            return;
          }
          
          if (!content) {
            alert('Please enter template content');
            return;
          }
          
          try {
            const method = this.currentTemplateId ? 'PUT' : 'POST';
            const url = this.currentTemplateId 
              ? `${config.apiBaseUrl}/${this.currentTemplateId}`
              : config.apiBaseUrl;
              
            const response = await fetch(url, {
              method,
              headers: {
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({ name, content })
            });
            
            if (!response.ok) throw new Error('Failed to save template');
            
            this.hideTemplateForm();
            this.loadTemplates();
          } catch (error) {
            console.error('Error saving template:', error);
            alert('Failed to save template. Please try again.');
          }
        }

        editTemplate(id) {
          const template = this.templates.find(t => t.id == id);
          if (template) {
            this.showTemplateForm(template);
          }
        }

        async deleteTemplate(id) {
          try {
            const response = await fetch(`${config.apiBaseUrl}/${id}`, {
              method: 'DELETE'
            });
            
            if (!response.ok) throw new Error('Failed to delete template');
            
            this.loadTemplates();
          } catch (error) {
            console.error('Error deleting template:', error);
            alert('Failed to delete template. Please try again.');
          }
        }

        showLoading() {
          this.container.querySelector('.loading-message').style.display = 'block';
          this.container.querySelector('.error-message').style.display = 'none';
          this.container.querySelector('.template-table').style.display = 'none';
        }

        hideLoading() {
          this.container.querySelector('.loading-message').style.display = 'none';
          this.container.querySelector('.template-table').style.display = 'table';
        }

        showError(message) {
          const errorEl = this.container.querySelector('.error-message');
          errorEl.textContent = message;
          errorEl.style.display = 'block';
          this.container.querySelector('.loading-message').style.display = 'none';
          this.container.querySelector('.template-table').style.display = 'none';
        }

        applyStyles() {
          const style = document.createElement('style');
          style.textContent = `
            .template-container {
                padding: 20px;
                position: absolute;
                left: 250px;
                background: none;
                top: 55px;
                width: calc(100% - 250px);
                box-sizing: border-box;
            }
            
            .template-manager {
                padding: 20px;
            }
            
            .template-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .template-controls {
                display: flex;
                gap: 15px;
                align-items: center;
                flex-wrap: wrap;
                width:100%;
            }
            
            .search-box {
                display: flex;
                align-items: center;
                width:48%;
            }
            
            .search-input {
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                min-width: 250px;
            }
            
            .search-btn {
                padding: 8px 15px;
                background: #f8f9fa;
                border: 1px solid #ddd;
                border-radius: 4px;
                cursor: pointer;
                margin-left: 5px;
            }
            
            .search-btn:hover {
                background: #e9ecef;
            }
            
            .sort-controls {
                display: flex;
                gap: 5px;
                align-items: center;
            }
            
            .add-template-btn {
                padding: 8px 15px;
                background: #28a745;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 500;
            }
            
            .add-template-btn:hover {
                background: #218838;
            }
            
            .template-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                margin-top: 20px;
                border-radius: 10px 0 0 0;
            }
            
            .template-table th {
                font-weight: 600;
                text-align: left;
                border-bottom: 1px solid #e9ecef;
                position: sticky;
                top: 0;
                background: #e8f0fe;
                padding: 15px 10px;
            }
            
            .template-table td {
                padding: 12px 15px;
                text-align: left;
                border-bottom: 1px solid #e9ecef;
                vertical-align: middle;
            }
            
            .template-table tr:hover td {
                background-color: #f8f9fa;
            }
            
            .actions {
                display: flex;
                gap: 8px;
            }
            
            .edit-btn, .delete-btn {
                border: none;
                border-radius: 3px;
                cursor: pointer;
            }
            
            .loading-message, .error-message {
                padding: 20px;
                text-align: center;
            }
            
            .template-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 20px;
                padding: 10px 0;
                border-top: 1px solid #e9ecef;
            }
            
            .template-form {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-top: 20px;
            }
            
            .form-group {
                margin-bottom: 15px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            
            .template-name-input {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            
            #template-editor {
                min-height: 300px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            
            .form-actions {
                display: flex;
                gap: 10px;
                margin-top: 15px;
            }
            
            .save-template-btn, .cancel-form-btn {
                padding: 8px 15px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
            }
            
            .save-template-btn {
                background: #28a745;
            }
            
            .sort-field {
              border-radius: 100px 0px 0px 100px;
              width: 120px;
              padding: 12px 15px;
              background: linear-gradient(180deg,#f7f8fa,#e7e9ec);
              margin-left: 15px;
              border: 1px solid #ccc;
            }
            .sort-direction {
              border-radius: 0px 100px 100px 0px;
              padding: 12px 15px;
              width: 90px;
              background: linear-gradient(180deg,#f7f8fa,#e7e9ec);
              margin-left: -5px;
              border: 1px solid #ccc;
            }
            .template-table-container {
              background: #fff;
              box-shadow: 0 1px 2px rgba(0,0,0,.2),1px -1px 2px rgba(0,0,0,.07),0 -2px 5px rgba(0,0,0,.03);
              border-radius: 10px;
            }
          `;
          document.head.appendChild(style);
        }
      }

      function initTemplates() {
        createStyleElements();
        updateContainerVisibility();
        
        let lastUrl = window.location.href;
        const urlCheckInterval = setInterval(() => {
          if (window.location.href !== lastUrl) {
            lastUrl = window.location.href;
            updateContainerVisibility();
          }
        }, 200);

        new TemplateManager();
      }
      
      if (document.readyState === 'complete' || document.readyState === 'interactive') {
         setTimeout(initTemplates, 1);
      } else {
        document.addEventListener('DOMContentLoaded', initTemplates);
      }
      
      e.default = {
        init: initTemplates
      };
    }
  },
  [[165, "runtime"]]
]);