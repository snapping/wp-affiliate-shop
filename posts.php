<?php
/**
 * Регистрация кастомного поста
 */
add_action('init', 'registerGdeSlonPostType');
function registerGdeSlonPostType()
{
	$postTypeConfig = array(
		'public' => true,
		'exclude_from_search' => true,
		'show_in_menu'	=> false,
		'menu_position' => 20,
		'has_archive'	  => true,
		'supports'=> array(
			'title',
			'editor',
			'page-attributes',
			'thumbnail',
			'excerpt',
			'custom-fields'
		),
		'labels'	=> array(
			'name' => 'Каталог',
			'singular_name' => 'Товар',
			'not_found'=> __('Товары не найдены'),
			'not_found_in_trash'=> __('Товары не найдены в корзине'),
			'edit_item' => __('Редактирование ', 'товара'),
			'search_items' => __('Поиск товара'),
			'view_item' => __('Просмотр товара'),
			'new_item' => __('Новый товар'),
			'add_new' => __('Создать'),
			'add_new_item' => __('Новый товар'),
		),
		'show_in_nav_menus'=> false,
	);

	register_post_type('ps_catalog', $postTypeConfig);
	register_taxonomy(
		'ps_category',
		'ps_catalog',
		array(
			'hierarchical' => true,
			'label' => __( 'Категории товаров' ),
			'sort' => true,
			'update_count_callback' => '_update_post_term_count',
			'args' => array( 'orderby' => 'term_order' ),
			'rewrite' => array( 'slug' => 'type' )
		)
	);
	flush_rewrite_rules(false);

	function filter_where($where = '')
	{
		if ((!is_tax('ps_category') && !is_search()) || is_single())
		{
			return $where;
		}
		return str_replace("post_type IN ('post',", "post_type IN ('post','ps_catalog',", $where);
	}
	add_filter('posts_where', 'filter_where', 9999);
}

/**
 * Помечаем товар, как отредактированный вручную
 */
add_action('edit_post', 'markAsEdited');
function markAsEdited($post)
{
	if (defined('PARSING_IS_RUNNING'))
		return;
	$post = is_object($post) ? $post : get_post($post);
	update_post_meta($post->ID,'edited_by_user', 1, get_post_meta($post->ID, 'edited_by_user', TRUE));
}

/**
 * Сброс кэша после вставки новых категорий
 * @return void
 */
function flushCache()
{
	delete_option("ps_category_children");
}

/**
 * Транслетирование слагов
 * @param $str
 * @return mixed|string
 */
function transliteration($str)
{
	$r_trans = Array(
		"А","Б","В","Г","Д","Е","Ё","Ж","З","И","Й","К","Л","М",
		"Н","О","П","Р","С","Т","У","Ф","Х","Ц","Ч","Ш","Щ","Э",
		"Ю","Я","Ъ","Ы","Ь",
		"а","б","в","г","д","е","ё","ж","з","и","й","к","л","м",
		"н","о","п","р","с","т","у","ф","х","ц","ч","ш","щ","э",
		"ю","я","ъ","ы","ь"," ",",","-","(",")",".","?","!",":","\"","'","=","\\","/");

	$e_trans = Array(
		"a","b","v","g","d","e","e","j","z","i","i","k","l","m",
		"n","o","p","r","s","t","u","f","h","cz","ch","sh","sch",
		"e","yu","ya","","i","",
		"a","b","v","g","d","e","e","j","z","i","i","k","l","m",
		"n","o","p","r","s","t","u","f","h","c","ch","sh","sch",
		"e","yu","ya","","i","","-","-","-","-","-","-","","","-","","","","","");

	$str = strtolower( str_replace($r_trans, $e_trans, $str) );
	$str = preg_replace('~([\-]+)~','-',$str);

	$str = preg_replace('~([^a-z0-9\-])~','',$str);
	return $str;
}

//@todo В чистом виде работает некорректно из-за специфики темы. Надо думать, что можно сделать.
add_filter('single_template', 'filter_single_template');
add_filter('body_class', 'filter_single_body_class',9999);

/**
 * Переопределение css-класса <body> single.php
 * @param $template
 * @return string
 */
function filter_single_body_class($class)
{
	$classKeys = array_flip($class);
	unset($classKeys['singular']);
	return array_flip($classKeys);
}

/**
 * Переопределение single.php
 * @param $template
 * @return string
 */
function filter_single_template( $template )
{
	global $wp_query;

	if (get_post_type($wp_query->post) != 'ps_catalog')
		return $template;

	// No template? Nothing we can do.
	$template_file = get_post_meta($wp_query->post->ID, '_wp_page_template', true);
	if ( ! $template_file )
		return $template;

	// If there's a tpl in a (child theme or theme with no child)
	if ( file_exists( get_stylesheet_directory() .'/'. $template_file ) )
		return get_stylesheet_directory() .'/'. $template_file;
	// If there's a tpl in the parent of the current child theme
	/*	else if ( file_exists( TEMPLATEPATH . DIRECTORY_SEPARATOR . $template_file ) )
			return TEMPLATEPATH . DIRECTORY_SEPARATOR . $template_file;*/
	return $template;
}


add_filter('the_content', 'showPost', 999999);
add_filter('loop_start', 'showBreadCrumbs', 999999);

/**
 * Рендеринг навигации "хлебных крошек"
 * @param $content
 * @return mixed
 */
function showBreadCrumbs($content)
{
	global $post;
	if (($post->post_type != 'ps_catalog' || !is_single()) && !is_tax('ps_category') && !is_post_type_archive('ps_catalog'))
		return;

	$delimiter = '&raquo;'; // разделить между ссылками
	$home = 'Home'; // текст ссылка "Главная"
	$before = '<span class="current">';
	$after = '</span>';

	if ( !is_home() && !is_front_page() || is_paged() ) {

		echo '<div id="crumbs">';

		global $post;
		$homeLink = get_bloginfo('url');
		echo '<a href="' . $homeLink . '">' . $home . '</a> ' . $delimiter . ' ';
		if (!is_post_type_archive('ps_catalog'))
			echo '<a href="'.get_post_type_archive_link('ps_catalog') . '">' . get_post_type_object('ps_catalog')->labels->name . '</a> ';
		else
			echo $before.get_post_type_object('ps_catalog')->labels->name .$after;

		if (is_tax('ps_category'))
		{
			global $wp_query;
			$cat = $wp_query->get_queried_object();
			$taxonomies = ps_get_taxonomy_parents($cat->parent);
			foreach($taxonomies as $obTerm)
			{
				if (get_class($obTerm) !== 'WP_Error')
					echo ' '.$delimiter . ' '.' <a href="' .get_category_link($obTerm).'" title="' . esc_attr( sprintf( __( "Посмотреть все товары в категории %s" ), $obTerm->name ) ) . '">'.$obTerm->name.'</a>';
			}
			echo ' '.$delimiter . ' '.$before.' '.$cat->name.' '.$after;

		} elseif ( is_single() && !is_attachment() ) {
			$cat = get_the_terms($post->ID, 'ps_category');
			if (is_array($cat))
			{
				foreach($cat as $obCat)
				{
					$cat = $obCat;
					break;
				}
				$taxonomies = ps_get_taxonomy_parents($cat->parent);
				$taxonomies[] = $cat;
				foreach($taxonomies as $obTerm)
				{
					if (get_class($obTerm) !== 'WP_Error')
						echo ' '.$delimiter.' <a href="' .get_category_link($obTerm).'" title="' . esc_attr( sprintf( __( "Посмотреть все товары в категории %s" ), $obTerm->name ) ) . '">'.$obTerm->name.'</a>';
				}
			}
			echo ' '.$delimiter.' ';
			echo $before . get_the_title() . $after;
		}
		echo '</div>';

	}
}

/**
 * Функция рендеринга поста
 * @param $content
 * @return mixed
 */
function showPost($content)
{
	global $wpdb;
	global $post;
	if ($post->post_type != 'ps_catalog')
		return $content;
	require 'templates/post.php';
}

function getPostByItem($obItem)
{
	return get_post($obItem->post_id);
}
function getItemByPost($obPost)
{
	global $wpdb;
	return $wpdb->get_row("SELECT * FROM ps_products WHERE post_id = '{$obPost->ID}'");
}

/**
 * Вывод изображения
 * @param $post
 * @param int $width
 */
function get_image_from_catalog_item($post, $width = 250)
{
	$post = is_object($post) ? $post : get_post($post);
	$url = has_post_thumbnail($post->ID)
			? wp_get_attachment_url(get_post_thumbnail_id($post->ID)) : get_post_meta($post->ID, 'image', TRUE);
	$url = $url ? $url : GS_PLUGIN_URL.'img/noimage.jpg';
	echo '<img src="'.$url.'" title="Купить '.$post->post_title.'" alt="Купить '.$post->post_title.'" style="width: '.$width .'px;" />';
}