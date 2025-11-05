/**
 * Blockchain CMS JavaScript Integration Library
 * Complete Web3 integration for WordPress plugin
 * Updated: prefers BCP.root for WP REST base and attaches X-WP-Nonce on state-changing requests.
 */

// eslint-disable-next-line no-undef
class BlockchainCMSInterface {
  constructor(config = {}) {
    this.config = {
      networkId: config.networkId || 1, // Ethereum mainnet
      rpcUrl: config.rpcUrl || 'https://eth-mainnet.alchemyapi.io/v2/YOUR_KEY',
      cmsContractAddress: config.cmsContractAddress || '',
      authContractAddress: config.authContractAddress || '',
      verificationContractAddress: config.verificationContractAddress || '',
      ipfsGateway: config.ipfsGateway || 'https://ipfs.io/ipfs/',
      ...config,
    };
    this.web3 = null;
    this.contracts = {};
    this.currentAccount = null;
    this.isInitialized = false;

    // WordPress REST integration (prefer server-localized values)
    this.wpRestRoot =
      (window.BCP && (BCP.root || BCP.rest || BCP.resturl)) ||
      (window.BCPDATA && BCPDATA.restBase) ||
      (window.location.origin + '/wp-json/bcp/v1/');

    // Strip trailing slashes for consistent URL joining
    this.wpRestRoot = String(this.wpRestRoot).replace(/\/+$/, '') + '/';

    // WordPress REST nonce for cookie-based auth
    this.wpNonce = (window.BCP && BCP.nonce) ? BCP.nonce : '';
  }

  /**
   * Initialize Web3 and contracts
   */
  async initialize() {
    try {
      if (typeof window.ethereum !== 'undefined') {
        // eslint-disable-next-line no-undef
        this.web3 = new Web3(window.ethereum);
        await window.ethereum.request({ method: 'eth_requestAccounts' });
        const accounts = await this.web3.eth.getAccounts();
        this.currentAccount = accounts[0];
        await this.initializeContracts();
        this.setupEventListeners();
        this.isInitialized = true;
        console.log('✅ Blockchain CMS initialized successfully');
        return { success: true, account: this.currentAccount, networkId: await this.web3.eth.net.getId() };
      } else {
        throw new Error('MetaMask not detected');
      }
    } catch (error) {
      console.error('❌ Initialization failed:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Initialize smart contracts
   */
  async initializeContracts() {
    try {
      const cmsABI = await this.loadABI('BlockchainCMS');
      const authABI = await this.loadABI('CMSAuth');
      const verificationABI = await this.loadABI('ContentVerification');

      this.contracts.cms = new this.web3.eth.Contract(cmsABI, this.config.cmsContractAddress);
      this.contracts.auth = new this.web3.eth.Contract(authABI, this.config.authContractAddress);
      this.contracts.verification = new this.web3.eth.Contract(verificationABI, this.config.verificationContractAddress);
      console.log('📄 Smart contracts initialized');
    } catch (error) {
      console.error('❌ Contract initialization failed:', error);
      throw error;
    }
  }

  /**
   * Load ABI from WordPress plugin
   */
  async loadABI(contractName) {
    try {
      const response = await fetch(`${window.location.origin}/wp-content/plugins/blockchain-cms/contracts/${contractName}-ABI.json`, {
        credentials: 'same-origin'
      });
      return await response.json();
    } catch (error) {
      console.error(`Failed to load ${contractName} ABI:`, error);
      throw error;
    }
  }

  /**
   * Set up event listeners for account and network changes
   */
  setupEventListeners() {
    if (window.ethereum) {
      window.ethereum.on('accountsChanged', (accounts) => {
        this.currentAccount = accounts[0] || null;
        this.onAccountChanged(this.currentAccount);
      });
      window.ethereum.on('chainChanged', (chainId) => {
        this.onNetworkChanged(chainId);
      });
    }
  }

  /**
   * Keccak-256 for blockchain (backward compatible)
   */
  async generateContentHash(content) {
    try {
      // web3.utils.sha3 is keccak256
      return this.web3.utils.sha3(content);
    } catch (error) {
      console.error('❌ Keccak hash generation failed:', error);
      throw error;
    }
  }

  /**
   * Accurate SHA-256 hex (for WP verify-on-view parity)
   */
  async generateSHA256Hex(content) {
    const enc = new TextEncoder();
    const buf = await crypto.subtle.digest('SHA-256', enc.encode(String(content)));
    return Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  /**
   * Register user as author (on-chain)
   */
  async registerAuthor(username, bio, avatar = '') {
    try {
      if (!this.isInitialized) throw new Error('CMS not initialized');
      const tx = await this.contracts.cms.methods.registerAuthor(username, bio, avatar).send({ from: this.currentAccount });
      console.log('✅ Author registered:', tx.transactionHash);
      return { success: true, transactionHash: tx.transactionHash };
    } catch (error) {
      console.error('❌ Author registration failed:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Publish content to blockchain (keccak content hash)
   */
  async publishContent(contentData) {
    try {
      if (!this.isInitialized) throw new Error('CMS not initialized');
      const {
        content,
        title,
        excerpt,
        contentType = 1, // 1=post, 2=page, 3=media
        isPublic = true,
        tags = [],
        ipfsHash = '',
      } = contentData;

      const contentHash = await this.generateContentHash(content);

      const hashExists = await this.contracts.cms.methods.isContentHashUsed(contentHash).call();
      if (hashExists) throw new Error('Content already exists on blockchain');

      const tx = await this.contracts.cms.methods
        .publishContent(contentHash, ipfsHash, title, excerpt, contentType, isPublic, tags)
        .send({ from: this.currentAccount });

      console.log('✅ Content published:', tx.transactionHash);

      const events = await this.contracts.cms.getPastEvents('ContentPublished', {
        filter: { author: this.currentAccount },
        fromBlock: tx.blockNumber,
        toBlock: tx.blockNumber,
      });
      const tokenId = events[0]?.returnValues?.tokenId;

      return { success: true, transactionHash: tx.transactionHash, tokenId, contentHash };
    } catch (error) {
      console.error('❌ Content publishing failed:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Update existing content (keccak of new content)
   */
  async updateContent(tokenId, newContentData) {
    try {
      const { content, title, excerpt, ipfsHash = '' } = newContentData;
      const newContentHash = await this.generateContentHash(content);

      const tx = await this.contracts.cms.methods
        .updateContent(tokenId, newContentHash, ipfsHash, title, excerpt)
        .send({ from: this.currentAccount });

      console.log('✅ Content updated:', tx.transactionHash);
      return { success: true, transactionHash: tx.transactionHash, newContentHash };
    } catch (error) {
      console.error('❌ Content update failed:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Helper: WordPress REST fetch with nonce + cookies
   * - method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
   * - path: relative to this.wpRestRoot
   * - opts: { body, headers }
   */
  async wpFetch(method, path, opts = {}) {
    const url = this.wpRestRoot + String(path).replace(/^\//, '');
    const headers = Object.assign({}, opts.headers || {});
    const isWrite = /^(POST|PUT|PATCH|DELETE)$/i.test(method);
    if (isWrite && this.wpNonce) headers['X-WP-Nonce'] = this.wpNonce;
    // Only set Content-Type when body is JSON string
    if (!(opts.body instanceof FormData) && isWrite && !headers['Content-Type']) {
      headers['Content-Type'] = 'application/json';
    }
    const res = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers,
      body: opts.body || undefined
    });
    const ct = res.headers.get('Content-Type') || '';
    let data = null;
    if (ct.includes('application/json')) {
      try { data = await res.json(); } catch (e) { data = null; }
    } else {
      data = await res.text().catch(()=>'');
    }
    if (!res.ok) {
      const msg = (data && (data.message || data.code)) || `HTTP ${res.status}`;
      throw new Error(msg);
    }
    return data;
  }

  /**
   * Anchor an existing WP post's saved digest on-chain
   * Falls back across keys: bcpkeccak256 → bcpcontentsha256hash → bcp_content_sha256_hash → bcpsha256
   * Optionally derives keccak256 from bcpipfscid if present.
   * Uses WP REST with nonce for writes.
   */
  async anchorPostHashOnChain(postId) {
    try {
      if (!this.isInitialized) throw new Error('CMS not initialized');

      // 1) Fetch post meta via secured WP REST (GET doesn't require nonce, but cookies retained)
      const meta = await this.wpFetch('GET', `posts/${postId}/meta`);

      // Safe extract helper (meta API may wrap as { key: { value } } or direct)
      const getVal = (obj, k) => (obj?.[k]?.value ?? obj?.[k] ?? '');

      const cid       = getVal(meta, 'bcpipfscid');                 // Optional: IPFS CID
      const mKeccak   = getVal(meta, 'bcpkeccak256');               // Hex without 0x
      const mCanonSha = getVal(meta, 'bcpcontentsha256hash');       // Canonical SHA-256 hex
      const mLegacySha= getVal(meta, 'bcp_content_sha256_hash');    // Legacy snake_case
      const mShortSha = getVal(meta, 'bcpsha256');                  // Older short key

      // Prefer a keccak digest if present; else use SHA-256; else derive keccak from CID string if available
      let digestHex = '';
      if (mKeccak) {
        digestHex = String(mKeccak).replace(/^0x/i, '');
      } else if (mCanonSha) {
        digestHex = String(mCanonSha).replace(/^0x/i, '');
      } else if (mLegacySha) {
        digestHex = String(mLegacySha).replace(/^0x/i, '');
      } else if (mShortSha) {
        digestHex = String(mShortSha).replace(/^0x/i, '');
      } else if (cid) {
        digestHex = this.web3.utils.keccak256(String(cid)).replace(/^0x/i, '');
      } else {
        throw new Error('No digest meta found on post');
      }

      // 2) Send tx to ContentVerification contract (method name/arg per your Solidity)
      const tx = await this.contracts.verification.methods
        .anchorHash('0x' + digestHex)
        .send({ from: this.currentAccount });

      // 3) Mark verified in WP so verify-badge can show "On-chain" (POST requires nonce header)
      await this.wpFetch('POST', `posts/${postId}/meta/bcpverified`, {
        body: JSON.stringify({ value: 1 })
      });

      console.log('✅ Anchored on-chain:', tx.transactionHash);
      return { success: true, transactionHash: tx.transactionHash, digestHex };
    } catch (err) {
      console.error('❌ Anchor failed:', err);
      return { success: false, error: err.message };
    }
  }

  /**
   * Verification flow (contract-level moderation)
   */
  async verifyContent(tokenId, isValid, notes = '') {
    try {
      const tx = await this.contracts.cms.methods.verifyContent(tokenId, isValid, notes).send({ from: this.currentAccount });
      console.log('✅ Content verified:', tx.transactionHash);
      return { success: true, transactionHash: tx.transactionHash };
    } catch (error) {
      console.error('❌ Content verification failed:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Get content from blockchain
   */
  async getContent(tokenId) {
    try {
      const content = await this.contracts.cms.methods.getContent(tokenId).call();
      return {
        success: true,
        content: {
          tokenId,
          contentHash: content.contentHash,
          ipfsHash: content.ipfsHash,
          title: content.title,
          excerpt: content.excerpt,
          publishedAt: content.publishedAt,
          author: content.author,
          contentType: content.contentType,
          isPublic: content.isPublic,
          version: content.version,
          tags: content.tags,
          parentId: content.parentId,
        },
      };
    } catch (error) {
      console.error('❌ Failed to get content:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Get author profile
   */
  async getAuthor(authorAddress) {
    try {
      const author = await this.contracts.cms.methods.getAuthor(authorAddress).call();
      return {
        success: true,
        author: {
          address: authorAddress,
          username: author.username,
          bio: author.bio,
          avatar: author.avatar,
          totalPosts: author.totalPosts,
          reputation: author.reputation,
          isVerified: author.isVerified,
          joinedAt: author.joinedAt,
        },
      };
    } catch (error) {
      console.error('❌ Failed to get author:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Get posts by author
   */
  async getAuthorPosts(authorAddress) {
    try {
      const postIds = await this.contracts.cms.methods.getAuthorPosts(authorAddress).call();
      const posts = [];
      for (const tokenId of postIds) {
        const contentResult = await this.getContent(tokenId);
        if (contentResult.success) posts.push(contentResult.content);
      }
      return { success: true, posts, totalPosts: posts.length };
    } catch (error) {
      console.error('❌ Failed to get author posts:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Like/unlike content
   */
  async toggleLike(tokenId) {
    try {
      const tx = await this.contracts.cms.methods.toggleLike(tokenId).send({ from: this.currentAccount });
      console.log('✅ Like toggled:', tx.transactionHash);
      return { success: true, transactionHash: tx.transactionHash };
    } catch (error) {
      console.error('❌ Like toggle failed:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Check if user has liked content
   */
  async hasUserLiked(tokenId, userAddress = null) {
    try {
      const address = userAddress || this.currentAccount;
      const hasLiked = await this.contracts.cms.methods.hasUserLiked(tokenId, address).call();
      return { success: true, hasLiked };
    } catch (error) {
      console.error('❌ Failed to check like status:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Authentication functions (on-chain, optional)
   */
  async generateAuthChallenge() {
    try {
      const tx = await this.contracts.auth.methods.generateChallenge().send({ from: this.currentAccount });
      const events = await this.contracts.auth.getPastEvents('ChallengeGenerated', {
        filter: { user: this.currentAccount },
        fromBlock: tx.blockNumber,
        toBlock: tx.blockNumber,
      });
      const challengeId = events[0]?.returnValues?.challengeId;
      const nonce = events[0]?.returnValues?.nonce;
      return { success: true, challengeId, nonce, transactionHash: tx.transactionHash };
    } catch (error) {
      console.error('❌ Challenge generation failed:', error);
      return { success: false, error: error.message };
    }
  }

  async signAuthMessage(nonce) {
    try {
      const message = this.web3.utils.soliditySha3(
        { type: 'address', value: this.currentAccount },
        { type: 'uint256', value: nonce },
      );
      const signature = await this.web3.eth.personal.sign(message, this.currentAccount, '');
      return { success: true, signature, message };
    } catch (error) {
      console.error('❌ Message signing failed:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Event handlers (can be overridden)
   */
  onAccountChanged(newAccount) {
    console.log('👤 Account changed:', newAccount);
    window.dispatchEvent(new CustomEvent('blockchainCMSAccountChanged', { detail: { account: newAccount } }));
  }

  onNetworkChanged(chainId) {
    console.log('🌐 Network changed:', chainId);
    window.dispatchEvent(new CustomEvent('blockchainCMSNetworkChanged', { detail: { chainId } }));
  }

  /**
   * Utilities
   */
  formatAddress(address, length = 8) {
    if (!address) return '';
    return `${address.slice(0, length)}...${address.slice(-4)}`;
  }

  weiToEther(wei) {
    return this.web3.utils.fromWei(wei, 'ether');
  }

  etherToWei(ether) {
    return this.web3.utils.toWei(ether, 'ether');
  }

  timestampToDate(timestamp) {
    return new Date(timestamp * 1000);
  }

  async getGasPrice() {
    try {
      return await this.web3.eth.getGasPrice();
    } catch (error) {
      console.error('❌ Failed to get gas price:', error);
      return null;
    }
  }

  async estimateGas(transaction) {
    try {
      return await transaction.estimateGas({ from: this.currentAccount });
    } catch (error) {
      console.error('❌ Gas estimation failed:', error);
      return null;
    }
  }
}

/**
 * WordPress integration helper
 */
class WordPressCMSIntegration {
  constructor(blockchainCMS) {
    this.cms = blockchainCMS;
  }

  setupWordPressIntegration() {
    this.addConnectButton();
    this.integratePostPublishing();
    this.addStatusIndicators();
  }

  addConnectButton() {
    const adminBar = document.getElementById('wp-admin-bar-root-default');
    if (adminBar) {
      const li = document.createElement('li');
      li.innerHTML = `
        <a id="blockchain-cms-connect" class="ab-item" href="#">Connect Wallet</a>
      `;
      adminBar.appendChild(li);
      document.getElementById('blockchain-cms-connect').addEventListener('click', async (e) => {
        e.preventDefault();
        await this.connectWallet();
      });
    }
  }

  async connectWallet() {
    const result = await this.cms.initialize();
    if (result.success) {
      this.showNotification('✅ Wallet connected successfully!', 'success');
      this.updateConnectionStatus(true);
    } else {
      this.showNotification('❌ Wallet connection failed: ' + result.error, 'error');
    }
  }

  integratePostPublishing() {
    // Classic editor publish button
    const publishButton = document.getElementById('publish');
    if (publishButton) {
      const container = document.createElement('div');
      container.style.marginTop = '8px';
      container.innerHTML = `
        <button id="bcp-anchor" type="button" class="button button-primary" style="margin-top:6px;">
          ⛓️ Anchor on-chain
        </button>
      `;
      publishButton.parentNode.insertBefore(container, publishButton.nextSibling);

      document.getElementById('bcp-anchor').addEventListener('click', async () => {
        const postId = new URLSearchParams(window.location.search).get('post');
        if (!postId) {
          this.showNotification('❗ Post ID not found. Save the post first.', 'warning');
          return;
        }
        if (!this.cms.isInitialized) {
          const res = await this.cms.initialize();
          if (!res.success) {
            this.showNotification('❌ Wallet connection failed: ' + res.error, 'error');
            return;
          }
        }
        this.showNotification('⛓️ Anchoring content hash on-chain...', 'info');
        const result = await this.cms.anchorPostHashOnChain(postId);
        if (result.success) {
          this.showNotification('✅ Anchored: ' + result.transactionHash, 'success');
        } else {
          this.showNotification('❌ Anchor failed: ' + result.error, 'error');
        }
      });
    }
  }

  addStatusIndicators() {
    const style = document.createElement('style');
    style.textContent = `
      .blockchain-status { display:inline-block; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:bold; margin-left:8px; }
      .blockchain-verified { background:#46b450; color:#fff; }
      .blockchain-pending { background:#ffb900; color:#fff; }
      .blockchain-failed { background:#dc3232; color:#fff; }
    `;
    document.head.appendChild(style);
  }

  showNotification(message, type = 'info') {
    const notice = document.createElement('div');
    notice.className = `notice notice-${type} is-dismissible`;
    notice.innerHTML = `<p>${message}</p>`;
    const container = document.querySelector('.wrap h1') || document.querySelector('#wpbody-content');
    if (container) {
      container.parentNode.insertBefore(notice, container.nextSibling);
    }
  }

  updateConnectionStatus(isConnected) {
    const connectButton = document.getElementById('blockchain-cms-connect');
    if (connectButton && isConnected) {
      connectButton.innerHTML = `Wallet Connected`;
      connectButton.style.color = '#46b450';
    }
  }
}

// Initialize when WordPress admin is loaded
document.addEventListener('DOMContentLoaded', function () {
  // eslint-disable-next-line no-undef
  window.blockchainCMS = new BlockchainCMSInterface({
    // eslint-disable-next-line no-undef
    cmsContractAddress: BCP?.contractAddresses?.cms || '',
    // eslint-disable-next-line no-undef
    authContractAddress: BCP?.contractAddresses?.auth || '',
    // eslint-disable-next-line no-undef
    verificationContractAddress: BCP?.contractAddresses?.verification || '',
  });

  if (document.body.classList.contains('wp-admin')) {
    window.wpCMSIntegration = new WordPressCMSIntegration(window.blockchainCMS);
    window.wpCMSIntegration.setupWordPressIntegration();
  }
});

// Export for use in other scripts (optional)
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { BlockchainCMSInterface, WordPressCMSIntegration };
}
