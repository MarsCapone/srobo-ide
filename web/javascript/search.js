/**
 * Main search page handler.
 * Connects to the page, form & buttons. Deals with launching searches.
 * @param results_handler: an alternative result handler.
 */
function SearchPage(results_handler) {

	//hold the tab object
	this._tab = null;

	//hold signals for the page
	this._signals = [];

	//hold status message for the page
	this._prompt = null;

	// Hold the list of search providers
	this._providers = [];

	// Hold a list of the current async searching providers
	this._async_results = [];

	this._results = results_handler;

	this._root = getElement('search-page');

	this._inited = false;

	this.init = function() {
		if (this._inited) {
			return;
		}

		if (!validate_team()) {
			return;
		}

		this._tab = new Tab( "Search" );
		connect( this._tab, "onfocus", bind(this._onfocus, this) );
		connect( this._tab, "onblur", bind(this._onblur, this) );
		connect( this._tab, "onclickclose", bind(this._close, this) );

		tabbar.add_tab(this._tab);

		this._signals.push(connect( "search-clear-results", "onclick", bind(this._clear, this) ));
		this._signals.push(connect( "search-form", "onsubmit", bind(this._search, this) ));
		this._signals.push(connect( "search-close", "onclick", bind(this._close, this) ));

		this._inited = true;

		this.clear_results();
		tabbar.switch_to(this._tab);
	};

	this._onfocus = function() {
		showElement(this._root);
	};

	this._onblur = function() {
		hideElement(this._root);
	};

	this._clear = function() {
		this.clear_results();
	};

	this.clear_results = function() {
		this._results.clear();
	};

	this.cancel_searches = function() {
		for (var i = 0; i < this._async_results.length; i++) {
			var provider = this._async_results[i];
			provider.cancel();
		}
		this._async_results = [];
	};

	this.mark_complete = function(provider) {
		// Ruddy IE 8 doesn't support indexOf
		var index = findValue(this._async_results, provider);
		this._async_results.splice(index, 1);
	};

	this._search = function(ev) {
		kill_event(ev);
		var query = getElement('search-query').value;
		this.search(query);
	};

	this.search = function(query) {
		this.cancel_searches();
		this.clear_results();
		for (var i=0; i < this._providers.length; i++) {
			var provider = this._providers[i];
			var async = provider.search(this, query);
			if (async) {
				this._async_results.push(provider);
			}
		}
	};

	this.add_result = function(section, result) {
		this._results.add(section, result);
	};

	this.add_provider = function(provider) {
		this._providers.push(provider);
	};

	this._close = function() {
		if (!this._inited) {
			return;
		}

		this._onblur();
		this._clear();
		this.cancel_searches();

		this._tab.close();

		if (this._prompt != null) {
			this._prompt.close();
			this._prompt = null;
		}

		for (var i = 0; i < this._signals.length; i++) {
			disconnect(this._signals[i]);
		}
		this._signals = [];

		this._inited = false;
	};
}

SearchPage.GetInstance = function() {
	if (SearchPage.Instance == null) {
		var results = new SearchResults();
		SearchPage.Instance = new SearchPage(results);

		var contentSearch = new FileContentSearchProvider(projpage, editpage);
		SearchPage.Instance.add_provider(contentSearch);
	}
	return SearchPage.Instance;
};

function SearchResults(root) {
	this._root = root || getElement('search-results');
	this._container = null;
	this._sections = {};
	this._signals = [];

	this.clear = function() {
		replaceChildNodes(this._root);
		this._container = null;
		this._sections = {};
		for (var i = 0; i < this._signals.length; i++) {
			disconnect(this._signals[i]);
		}
		this._signals = [];
	};

	/**
	 * Adds a search result to the given result section.
	 * @param section: The name of the section to add the result to.
	 * @param result: A result object:
	 *    .text: Text to displayed that describes the match (eg: the whole source line matched).
	 *  .action: [optional] A function to be called when the user double-clicks on the result.
	 *           Probably some form of navigation to the location where the match was.
	 */
	this.add = function(section, result) {
		logDebug("Adding result '" + JSON.stringify(result) + "' in section '" + section + "'.");

		var result_li = LI(null, result.text);
		if (result.action != null) {
			this._signals.push(connect(result_li, 'ondblclick', result.action));
		}
		var section_ul = this._get_section(section);
		appendChildNodes(section_ul, result_li);
	};

	this._get_section = function(section) {
		var ul = this._sections[section];
		if (ul == null) {
			this._sections[section] = ul = this._make_section(section);
		}
		return ul;
	};

	this._make_section = function(section) {
		var container_dl = this._get_container();
		var dt = DT(null, section);
		var ul = UL();
		var dd = DD(null, ul);
		appendChildNodes(container_dl, dt, dd);
		return ul;
	};

	this._get_container = function() {
		if (this._container == null) {
			this._container = DL();
			appendChildNodes(this._root, this._container);
		}
		return this._container;
	};
}

function MockProvider() {
	this.search = function(page, query) {
		page.add_result('Mock', { text: 'foo ' + query + ' bar' });
		page.add_result('Mock', { text: 'second ' + query + ' bar' });
		return false;
	};
}

function MockAsyncProvider(delay) {
	this._delay = delay || 2;
	this._def = null;

	this.search = function(page, query) {
		this._def = callLater(this._delay, function() {
			page.add_result('MockAsync', { text: 'foo ' + query + ' bar' });
		});
		return true;
	};

	this.cancel = function() {
		this._def.cancel();
		this._def = null;
	};
}

function ProjectNameSearchProvider(proj_source, selector) {
	this._proj_source = proj_source;
	this._proj_selector = selector;

	this.search = function(page, query) {
		var projects = this._proj_source.list_projects();
		for (var i = 0; i < projects.length; i++) {
			var project = projects[i];
			if (project.indexOf(query) != -1) {
				var result = { text: project,
				             action: bind(this._select_project, this, project) };
				page.add_result('Projects', result);
			}
		}
		return false;
	};

	this._select_project = function(project) {
		this._proj_selector.select(project);
		this._proj_source.switch_to();
	};
}

function FileNameSearchProvider(proj_source, selector) {
	this._proj_source = proj_source;
	this._proj_selector = selector;

	this._cancelled = false;
	this._page = null;
	this._query = null;

	this.cancel = function() {
		this._cancelled = true;
		this._page = null;
		this._query = null;
	};

	this.search = function(page, query) {
		this._cancelled = false;
		this._page = page;
		this._query = query;
		var projects = this._proj_source.list_projects();
		for (var i = 0; i < projects.length; i++) {
			this._get_files(projects[i]);
		}
		return projects.length > 0;
	};

	this._got_files = function(project, nodes) {
		if (this._cancelled) {
			return;
		}
		this._search_tree(project, nodes.tree);
	};

	this._search_tree = function(project, tree) {
		for (var i = 0; i < tree.length; i++) {
			var node = tree[i];
			logDebug('FNSP checking ' + JSON.stringify(node));
			if (node.name.indexOf(this._query) != -1) {
				var result = { text: node.path,
				             action: bind(this._select_file, this, project, node.path) };
				this._page.add_result('Files', result);
			}
			if (node.kind == 'FOLDER') {
				this._search_tree(project, node.children);
			}
		}
	};

	this._get_files = function(project, isRetry) {
		if (this._cancelled) {
			return;
		}
		var opts = { team: team, project: project };
		var err_handler;
		if (!isRetry) {
			err_handler = bind(this._get_files, this, project, true);
		} else {
			err_handler = function(){};
		}
		IDE_backend_request("file/compat-tree", opts,
		                    bind(this._got_files, this, project),
		                    err_handler);
	};

	this._select_file = function(project, path) {
		logDebug('before switching project');
		this._proj_selector.select(project);
		logDebug('mid');
		var flist = this._proj_source.flist;
		flist.select_none();
		flist.select(path);
		logDebug('files selected');
		this._proj_source.switch_to();
	};
}

function FileContentSearchProvider(proj_source, open_files_source) {
	this._proj_source = proj_source;
	this._open_files_source = open_files_source;

	this._cancelled = false;
	this._page = null;
	this._query = null;
	this._local_results = {};

	this.cancel = function() {
		this._cancelled = true;
		this._page = null;
		this._query = null;
		this._local_results = {};
	};

	this.search = function(page, query) {
		this._cancelled = false;
		this._page = page;
		this._query = query;
		var projects = this._proj_source.list_projects();
		for (var i=0; i < projects.length; i++) {
			this._search_project(projects[i]);
		}
		var local_results = this._open_files_source.search(query);
		this._add_local_results(local_results);
		this._local_results = local_results;
		return projects.length > 0;
	};

	this._search_project = function(project, isRetry) {
		if (this._cancelled) {
			return;
		}
		var args = { team: team, project: project, query: this._query };
		var err_handler;
		if (!isRetry) {
			err_handler = bind(this._search_project, this, project, true);
		} else {
			err_handler = function(){};
		}
		IDE_backend_request("proj/search", args,
		                    bind(this._handle_results, this, project),
		                    err_handler);
	};

	this._add_local_results = function(results) {
		if (this._cancelled) {
			return;
		}
		for (var file in results) {
			var project = IDE_path_get_project(file);
			this._add_results(project, file, results[file]);
		}
	};

	this._handle_results = function(project, nodes) {
		if (this._cancelled) {
			return;
		}
		var results = nodes.results;
		for (var file in results) {
			if (file in this._local_results) {
				continue;
			}
			var matches = results[file];
			this._add_results(project, file, matches);
		}
	};

	this._add_results = function(project, file, matches) {
		for (var i=0; i < matches.length; i++) {
			var match = matches[i];
			var result = { text: file + ':' + match.line + ': ' + match.text,
						 action: bind(this._open_file_line, this, project, file, match.line) };
			this._page.add_result('File Contents', result);
		}
	};

	this._open_file_line = function(project, file, line) {
		var etab = editpage.edit_file(team, project, file);
		etab.setSelectionRange(line, 0, -1);
	};
}

// node require() based exports.
if (typeof(exports) != 'undefined') {
	exports.SearchPage = SearchPage;
	exports.SearchResults = SearchResults;
	exports.MockProvider = MockProvider;
	exports.MockAsyncProvider = MockAsyncProvider;
	exports.ProjectNameSearchProvider = ProjectNameSearchProvider;
	exports.FileNameSearchProvider = FileNameSearchProvider;
	exports.FileContentSearchProvider = FileContentSearchProvider;
}
