import hljs from 'highlight.js/lib/core';
import c from 'highlight.js/lib/languages/c';
import cpp from 'highlight.js/lib/languages/cpp';
import csharp from 'highlight.js/lib/languages/csharp';
import css from 'highlight.js/lib/languages/css';
import html from 'highlight.js/lib/languages/vbscript-html';
import java from 'highlight.js/lib/languages/java';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import matlab from 'highlight.js/lib/languages/matlab';
import php from 'highlight.js/lib/languages/php';
import python from 'highlight.js/lib/languages/python';
import r from 'highlight.js/lib/languages/r';
import twig from 'highlight.js/lib/languages/twig';
import typescript from 'highlight.js/lib/languages/typescript';
import scss from 'highlight.js/lib/languages/scss';
import shell from 'highlight.js/lib/languages/shell';
import sql from 'highlight.js/lib/languages/sql';
import yaml from 'highlight.js/lib/languages/yaml';
import vim from 'highlight.js/lib/languages/vim';

// Register the languages we need
hljs.registerLanguage('c', c);
hljs.registerLanguage('cpp', cpp);
hljs.registerLanguage('csharp', csharp);
hljs.registerLanguage('css', css);
hljs.registerLanguage('html', html);
hljs.registerLanguage('java', java);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('json', json);
hljs.registerLanguage('matlab', matlab);
hljs.registerLanguage('php', php);
hljs.registerLanguage('python', python);
hljs.registerLanguage('r', r);
hljs.registerLanguage('twig', twig);
hljs.registerLanguage('typescript', typescript);
hljs.registerLanguage('scss', scss);
hljs.registerLanguage('shell', shell);
hljs.registerLanguage('sql', sql);
hljs.registerLanguage('yaml', yaml);
hljs.registerLanguage('vim', vim);

hljs.configure({
  languages: [
    'c',
    'cpp',
    'csharp',
    'css',
    'html',
    'java',
    'javascript',
    'json',
    'matlab',
    'php',
    'python',
    'r',
    'twig',
    'typescript',
    'scss',
    'shell',
    'sql',
    'yaml',
    'vim',
  ],
});

Drupal.behaviors.textHighlight = {
  attach(context) {
    // Selectors
    const codeBlocks = context.querySelectorAll('pre > code');

    codeBlocks.forEach((codeBlock) => {
      if (typeof codeBlock === 'object') {
        hljs.highlightBlock(codeBlock);
      }
    });
  },
};
