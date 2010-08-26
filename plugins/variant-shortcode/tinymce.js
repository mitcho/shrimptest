(function() {	
 	tinymce.PluginManager.requireLangPack('variant_shortcode');
	tinymce.create('tinymce.plugins.abtest', {
		init : function(ed, url) {
			ed.addButton('abtest', {
				title : 'variant_shortcode.insertABTest',
				image : url + '/abtest.png',
				onclick : function() {
					insertABTest();
				}			
			});
		},


		getInfo : function() {
			return {
				longname : 'ShrimpTest Shortcode Variant MCE Buttons',
				author : 'mitcho (Michael 芳貴 Erlewine)',
				authorurl : 'http://mitcho.com/',
				infourl : 'http://shrimptest.com/',
				version : tinymce.majorVersion + "." + tinymce.minorVersion
			};
		}
		
	});

	// Register plugin
	tinymce.PluginManager.add('abtest', tinymce.plugins.abtest);
	
})();