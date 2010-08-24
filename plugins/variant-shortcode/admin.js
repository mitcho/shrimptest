jQuery(function($) {

	function getString(id) {
		return jQuery('#shrimptest_variant_shortcode_strings [data-id='+id+']').text();
	}

	function addABText() {
		var variants = jQuery(this).siblings('table').find('.shrimptest_variant_shortcode_row');
		var variantData = {};
		variants.each(function() {
			var variant = jQuery(this);
			if (variant.find('input[type=text]').val()) {
				var index = parseInt(variant.attr('data-variant'));
				variantData[index] = variant.find('input[type=text]').val();
			}
		})
		tb_remove();
		
		var variant = getString('variant_label_prefix');
		var control = getString('control_label');
		
		var abtext = '[ab ';
		jQuery.each(variantData,function(index, value) {
			var label = index == 0 ? control : variant + index;
			label = label.replace(/\s/,'');
			abtext += label + '="' + value.replace(/"/g,'\\"') + '" ';
		});
		abtext += '/]';
		
		send_to_editor(abtext);
	}
	
	function clearABThickbox() {
		// clear the data we had
		jQuery('.shrimptest_variant_shortcode_row:not([data-variant=0])').remove();
		jQuery('.shrimptest_variant_shortcode_row input[type=text]').val('');
		
		newVariantId = 1;
		tb_remove();
	}

	window.insertABTest = function insertABTest() {
		tb_show('Add ShrimpTest Experiment','#TB_inline=1?height=300&width=300&inlineId=shrimptest_variant_shortcode');
		if (newVariantId == 1)
			addVariant();
		// resize the window here, as the WordPress thickbox size controls are broken.
		// see http://binarybonsai.com/2010/02/27/using-thickbox-in-the-wordpress-admin/ for info
		setTimeout(function() {
			var height = jQuery(window).height();
			jQuery('#TB_window').css({width:330,height:340,marginLeft:-330/2,marginTop:(height-340)/2})
		},1);
	};

	// add button to plain text editor
	var title = getString('button_title');
	var label = getString('button_label');
	var input = $('<input type="button" class="ed_button"/>')
	            .attr('title',title)
	            .val(label)
	            .click(insertABTest);
	$("#ed_toolbar").append(input);
	
	var newVariantId = parseInt($('.shrimptest_variant_shortcode_row').eq(-1).attr('data-variant')) + 1 || 1;

	var enforceButtons = function () {
		$('.shrimptest_variant_shortcode_removevariant').hide();
		if (newVariantId > 2)
			$('.shrimptest_variant_shortcode_removevariant').last().show();
	}
	
	var addVariant = function(){
		$('.shrimptest_variant_shortcode_removevariant').hide();
		
		var newRow = $('<tr class="shrimptest_variant_shortcode_row"><th></th><td><input type="text" class="shrimptest_variant_shortcode" maxlength="255"/><input type="button" class="shrimptest_variant_shortcode_removevariant" value="-"/></td></tr>');

		var variant = getString('variant');

		newRow
			.attr('data-variant',newVariantId)
			.find('th')
				.text(variant+" "+newVariantId+':')
				.attr('for','variant['+newVariantId+'][name]')
			.end()
			.find('input.shrimptest_variant_shortcode')
				.attr({id:'shrimptest_variant_shortcode_'+newVariantId, name:'shrimptest_variant_shortcode['+newVariantId+']'})
			.end();

		if ($('#TB_window tbody').length)
			$('#TB_window tbody').append(newRow);
		else
			$('#shrimptest_variant_shortcode tbody').append(newRow);

		newVariantId++;

		enforceButtons();
	};

	$('#shrimptest_variant_shortcode_addvariant').click(addVariant);
	
	$('.shrimptest_variant_shortcode_removevariant').live('click',function(){
		if ( $(this).closest('tr').attr('data-variant') == newVariantId - 1 )
			newVariantId --;
		$(this).closest('tr').remove();
		enforceButtons();
	});
	
	enforceButtons();
	
	$('#shrimptest_variant_shortcode_insert').click(addABText);
	$('#shrimptest_variant_shortcode_cancel').click(clearABThickbox);
});
