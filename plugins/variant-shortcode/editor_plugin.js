(function() {	
 	tinymce.PluginManager.requireLangPack('variant_shortcode');
	tinymce.create('tinymce.plugins.variant_shortcode', {
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
	tinymce.PluginManager.add('variant_shortcode', tinymce.plugins.variant_shortcode);
	
})();