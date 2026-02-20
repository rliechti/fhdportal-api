// =====================================================
// Utility Functions
// =====================================================

const Utils = {
  showResult(element, html) {
    element.innerHTML = html;
    element.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  },

  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  },

  setButtonLoadingState(button, isLoading, loadingText, defaultText) {
    button.disabled = isLoading;
    button.innerHTML = isLoading ? `<span class="spinner"></span> ${loadingText}` : defaultText;
  },

  buildValidationResultHTML(result) {
    let html = '<h3>Validation Result</h3>';

    if (result.success) {
      html += '<p class="success">✓ SUCCESSFUL</p>';
    } else {
      html += '<p class="error">✗ FAILED</p>';
    }

    if (result.errors && result.errors.length > 0) {
      html += '<h4>Errors:</h4>';
      html += '<ul class="error-list">';
      result.errors.forEach((error) => {
        html += `<li class="error">${this.escapeHtml(error)}</li>`;
      });
      html += '</ul>';
    }

    if (result.warnings && result.warnings.length > 0) {
      html += '<h4>Warnings:</h4>';
      html += '<ul class="warning-list">';
      result.warnings.forEach((warning) => {
        html += `<li class="text-muted">${this.escapeHtml(warning)}</li>`;
      });
      html += '</ul>';
    }

    if (result.details) {
      html += '<h4>Details:</h4>';
      html += `<pre>${JSON.stringify(result.details, null, 2)}</pre>`;
    }

    return html;
  },
};

// =====================================================
// Resource Validation Module
// =====================================================

const ResourceValidation = {
  elements: null,

  init() {
    this.elements = {
      resourceType: document.getElementById('resource-type'),
      validationInput: document.getElementById('validation-input'),
      fileUpload: document.getElementById('file-upload'),
      validateButton: document.getElementById('validate-button'),
      clearButton: document.getElementById('clear-validation-button'),
      resultContainer: document.getElementById('validation-result'),
    };

    this.attachEventListeners();
  },

  attachEventListeners() {
    this.elements.validateButton.addEventListener('click', () => this.validate());
    this.elements.clearButton.addEventListener('click', () => this.clear());
    this.elements.fileUpload.addEventListener('change', (e) => this.handleFileUpload(e));
  },

  handleFileUpload(event) {
    const file = event.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = (e) => {
        this.elements.validationInput.value = e.target.result;
      };
      reader.readAsText(file);
    }
  },

  clear() {
    this.elements.validationInput.value = '';
    this.elements.fileUpload.value = '';
    this.elements.resourceType.value = '';
    this.elements.resultContainer.innerHTML = '';
  },

  validateInput() {
    const type = this.elements.resourceType.value;
    const data = this.elements.validationInput.value.trim();

    if (!type) {
      Utils.showResult(this.elements.resultContainer, '<span class="error">Please select a resource type</span>');
      return null;
    }

    if (!data) {
      Utils.showResult(this.elements.resultContainer, '<span class="error">Please enter data or upload a file</span>');
      return null;
    }

    return { type, data };
  },

  async validate() {
    const input = this.validateInput();
    if (!input) return;

    // Show loading state
    Utils.setButtonLoadingState(this.elements.validateButton, true, 'Validating...', 'Validate');
    Utils.showResult(this.elements.resultContainer, '<p>Validating data...</p>');

    try {
      const response = await fetch('/api/validate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          resource_type: input.type,
          data: input.data,
        }),
      });

      const result = await response.json();
      this.displayResult(result);
    } catch (error) {
      Utils.showResult(this.elements.resultContainer, `<span class="error">Error: ${error.message}</span>`);
    } finally {
      Utils.setButtonLoadingState(this.elements.validateButton, false, 'Validating...', 'Validate');
    }
  },

  displayResult(result) {
    const html = Utils.buildValidationResultHTML(result);
    Utils.showResult(this.elements.resultContainer, html);
  },
};

// =====================================================
// Submission Bundle Validation Module
// =====================================================

const BundleValidation = {
  elements: null,

  init() {
    this.elements = {
      bundleUpload: document.getElementById('bundle-upload'),
      validateButton: document.getElementById('validate-bundle-button'),
      clearButton: document.getElementById('clear-bundle-button'),
      resultContainer: document.getElementById('bundle-result'),
    };

    this.attachEventListeners();
  },

  attachEventListeners() {
    this.elements.validateButton.addEventListener('click', () => this.validate());
    this.elements.clearButton.addEventListener('click', () => this.clear());
  },

  clear() {
    this.elements.bundleUpload.value = '';
    this.elements.resultContainer.innerHTML = '';
  },

  validateInput() {
    const file = this.elements.bundleUpload.files[0];

    if (!file) {
      Utils.showResult(this.elements.resultContainer, '<span class="error">Please select a ZIP file to upload</span>');
      return null;
    }

    if (!file.name.toLowerCase().endsWith('.zip')) {
      Utils.showResult(this.elements.resultContainer, '<span class="error">Please upload a ZIP file</span>');
      return null;
    }

    return file;
  },

  async validate() {
    const file = this.validateInput();
    if (!file) return;

    // Show loading state
    Utils.setButtonLoadingState(this.elements.validateButton, true, 'Validating...', 'Validate Bundle');
    Utils.showResult(this.elements.resultContainer, '<p>Uploading and validating submission bundle...</p>');

    try {
      const formData = new FormData();
      formData.append('bundle', file);

      const response = await fetch('/api/validate/bundle', {
        method: 'POST',
        body: formData,
      });

      const result = await response.json();
      this.displayResult(result);
    } catch (error) {
      Utils.showResult(this.elements.resultContainer, `<span class="error">Error: ${error.message}</span>`);
    } finally {
      Utils.setButtonLoadingState(this.elements.validateButton, false, 'Validating...', 'Validate Bundle');
    }
  },

  displayResult(result) {
    const html = Utils.buildValidationResultHTML(result);
    Utils.showResult(this.elements.resultContainer, html);
  },
};

// =====================================================
// Auth Token Decoding Module
// =====================================================

const TokenDecoder = {
  elements: null,

  init() {
    this.elements = {
      tokenInput: document.getElementById('token-input'),
      decodeButton: document.getElementById('decode-button'),
      clearButton: document.getElementById('clear-token-button'),
      resultContainer: document.getElementById('token-result'),
    };

    this.attachEventListeners();
  },

  attachEventListeners() {
    this.elements.decodeButton.addEventListener('click', () => this.decode());
    this.elements.clearButton.addEventListener('click', () => this.clear());
  },

  clear() {
    this.elements.tokenInput.value = '';
    this.elements.resultContainer.innerHTML = '';
  },

  parseJWT(token) {
    try {
      const parts = token.split('.');
      if (parts.length !== 3) {
        return null;
      }

      const base64Url = parts[1];
      const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
      const jsonPayload = decodeURIComponent(
        atob(base64)
          .split('')
          .map((c) => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
          .join('')
      );

      return JSON.parse(jsonPayload);
    } catch (error) {
      return null;
    }
  },

  checkTokenValidity(decoded) {
    const exp = decoded.exp ? new Date(decoded.exp * 1000) : null;
    const now = new Date();
    const isExpired = exp && exp < now;
    return { isValid: !isExpired, exp };
  },

  buildTokenInfoHTML(decoded) {
    const { isValid, exp } = this.checkTokenValidity(decoded);

    let html = '<h3>Token Information</h3>';

    // Validity status
    html += `<p><strong>Status:</strong> `;
    if (isValid) {
      html += '<span class="status-badge valid">✓ VALID</span>';
    } else {
      html += '<span class="status-badge invalid">✗ EXPIRED</span>';
    }
    html += '</p>';

    // Token details
    if (decoded.preferred_username || decoded.sub) {
      html += `<p><strong>User:</strong> ${decoded.preferred_username || decoded.sub}</p>`;
    }

    if (decoded.email) {
      html += `<p><strong>Email:</strong> ${decoded.email}</p>`;
    }

    if (exp) {
      html += `<p><strong>Expires:</strong> ${exp.toLocaleString()}</p>`;
    }

    if (decoded.iat) {
      const iat = new Date(decoded.iat * 1000);
      html += `<p><strong>Issued At:</strong> ${iat.toLocaleString()}</p>`;
    }

    // Decoded payload
    html += '<h3>Decoded Payload</h3>';
    html += `<pre>${JSON.stringify(decoded, null, 2)}</pre>`;

    return html;
  },

  decode() {
    const token = this.elements.tokenInput.value.trim();

    if (!token) {
      Utils.showResult(this.elements.resultContainer, '<span class="error">Please enter a JWT token</span>');
      return;
    }

    try {
      const decoded = this.parseJWT(token);

      if (!decoded) {
        Utils.showResult(this.elements.resultContainer, '<span class="error">Invalid JWT token format</span>');
        return;
      }

      const html = this.buildTokenInfoHTML(decoded);
      Utils.showResult(this.elements.resultContainer, html);
    } catch (error) {
      Utils.showResult(
        this.elements.resultContainer,
        `<span class="error">Error decoding token: ${error.message}</span>`
      );
    }
  },
};

// =====================================================
// Application Initialization
// =====================================================

document.addEventListener('DOMContentLoaded', function () {
  ResourceValidation.init();
  BundleValidation.init();
  TokenDecoder.init();
});
