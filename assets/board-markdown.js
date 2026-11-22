/* Local markdown-it build. Raw HTML and automatic remote-image requests stay off. */
(() => {
  const md = window.markdownit({ html: false, linkify: true, breaks: true, typographer: false });
  md.disable('image');
  const linkOpen = md.renderer.rules.link_open || ((tokens, idx, options, env, self) => self.renderToken(tokens, idx, options));
  md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
    tokens[idx].attrSet('target', '_blank');
    tokens[idx].attrSet('rel', 'noopener noreferrer');
    return linkOpen(tokens, idx, options, env, self);
  };
  // The board title is the page's only h1; message headings start at h2.
  for (const rule of ['heading_open', 'heading_close']) {
    md.renderer.rules[rule] = (tokens, idx, options, env, self) => {
      const original = tokens[idx].tag;
      tokens[idx].tag = 'h' + Math.min(6, Number(original.slice(1)) + 1);
      const html = self.renderToken(tokens, idx, options);
      tokens[idx].tag = original;
      return html;
    };
  }
  md.renderer.rules.table_open = () => '<div class="table-scroll" role="region" aria-label="Message table" tabindex="0"><table>\n';
  md.renderer.rules.table_close = () => '</table></div>\n';
  const fence = md.renderer.rules.fence;
  md.renderer.rules.fence = (...args) => fence(...args).replace('<pre>', '<pre tabindex="0" aria-label="Code block">');
  const codeBlock = md.renderer.rules.code_block;
  md.renderer.rules.code_block = (...args) => codeBlock(...args).replace('<pre>', '<pre tabindex="0" aria-label="Code block">');
  /* @mentions. The caller says who a name refers to: opts.mention(handle) returns
     { href, nav } for someone on the pad, or nothing, and then the text is left alone.
     Names inside code and inside links are never touched. */
  const MENTION = /(^|[^A-Za-z0-9_@\/.+-])@([A-Za-z0-9][A-Za-z0-9_-]{1,38}[A-Za-z0-9])(?![A-Za-z0-9_-])/g;
  md.core.ruler.after('linkify', 'mentions', state => {
    const resolve = state.env && state.env.mention;
    if (typeof resolve !== 'function') return;
    for (const block of state.tokens) {
      if (block.type !== 'inline' || !block.children) continue;
      const out = [];
      let inLink = 0;
      for (const token of block.children) {
        if (token.type === 'link_open') inLink++;
        if (token.type === 'link_close') inLink--;
        if (token.type !== 'text' || inLink > 0 || !token.content.includes('@')) { out.push(token); continue; }
        let last = 0, m;
        MENTION.lastIndex = 0;
        while ((m = MENTION.exec(token.content))) {
          const target = resolve(m[2]);
          if (!target || !target.href) continue;
          const start = m.index + m[1].length;
          if (start > last) { const t = new state.Token('text', '', 0); t.content = token.content.slice(last, start); out.push(t); }
          const open = new state.Token('mention_open', 'a', 1);
          open.attrs = [['href', target.href], ['class', 'mention']];
          if (target.nav != null) open.attrs.push(['data-nav', String(target.nav)]);
          const name = new state.Token('text', '', 0); name.content = '@' + m[2];
          out.push(open, name, new state.Token('mention_close', 'a', -1));
          last = start + 1 + m[2].length;
        }
        if (last === 0) { out.push(token); continue; }
        if (last < token.content.length) { const t = new state.Token('text', '', 0); t.content = token.content.slice(last); out.push(t); }
      }
      block.children = out;
    }
  });
  window.renderMessage = (text, opts) => md.render(text, opts && typeof opts.mention === 'function' ? { mention: opts.mention } : {});
})();
