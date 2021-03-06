/**
 * Layer to display remote geoJson
 * Styles, icons & labels
 * Hover & click
 * Features clusters
 * Specific source for hign resolution zoom
 */
function geoJsonLayer(options) {
	const baseOptions = Object.assign({
			format: new ol.format.GeoJSON(),
			strategy: ol.loadingstrategy.bbox,
			url: url,
			clusterDistance: 1000000, // No clusterisation
		}, options),

		// Options of the source if resolution > options.alt.minResolution
		altOptions = Object.assign({}, baseOptions, baseOptions.alt),

		// options.styleOptions, // Base feature format : Object or function(feature)

		// Common label text format
		labelStyleOptions = Object.assign({
			textBaseline: 'bottom',
			offsetY: -13, // Compensate the bottom textBaseline
			padding: [1, 3, 0, 3],
			font: '14px Calibri,sans-serif',
			backgroundFill: new ol.style.Fill({
				color: 'yellow',
			}),
		}, options.labelStyleOptions),

		// Style when hovering a feature
		hoverStyleOptions = Object.assign({
			// We assign the same text style to the hover label
			text: new ol.style.Text(
				labelStyleOptions
			),
		}, options.hoverStyleOptions),

		// Specific format of clusters bullets
		clusterStyleOptions = Object.assign({
			image: new ol.style.Circle({
				radius: 14,
				stroke: new ol.style.Stroke({
					color: 'blue',
				}),
				fill: new ol.style.Fill({
					color: 'white',
				}),
			}),
			text: new ol.style.Text({
				font: '14px Calibri,sans-serif',
			}),
		}, options.clusterStyleOptions),

		source = new ol.source.Vector(baseOptions),
		altSource = new ol.source.Vector(Object.assign({}, baseOptions, baseOptions.alt)),

		// Clusterisation
		//clusterSource = !baseOptions.clusterDistance ? source :
		//new ol.source.Cluster({
		clusterSource = new ol.source.Cluster({
			distance: baseOptions.clusterDistance,
			source: source,
			geometryFunction: function(feature) {
				// Generate a center point to manage clusterisations
				return new ol.geom.Point(
					ol.extent.getCenter(
						feature.getGeometry().getExtent()
					)
				);
			},
		}),

		// Layer
		layer = new ol.layer.Vector({
			source: clusterSource,
			style: style,
		});

	function url(extent, resolution, projection) {
		//clusterSource.setSource(resolution < 100  ?source:	altSource);
		/*
					source.strategy_= 
					clusterSource.strategy_= 
					 ?
						baseOptions.strategy:
						altOptions.strategy;
		*/

		//BEST gérer les msg erreur
		return baseOptions.urlHost +
			baseOptions.urlPath(
				ol.proj.transformExtent( // BBox
					extent,
					projection.getCode(),
					'EPSG:4326' // Received projection
				),
				baseOptions.selectorList,
				resolution, // === zoom level
				baseOptions
			);
	}

	// Add layer options selector
	if (baseOptions.selectorName)
		memCheckbox(baseOptions.selectorName, function(list) {
			baseOptions.selectorList = list;
			layer.setVisible(list.length > 0);
			if (list.length > 0)
				source.refresh();
		});

	// Normalize properties
	if (typeof baseOptions.computeProperties == 'function')
		source.on('featuresloadend', function(evt) {
			for (let p in evt.features)
				baseOptions.computeProperties(evt.features[p], baseOptions);
		});

	// Tune the clustering distance following the zoom level
	let previousResolution = 0;
	layer.on('prerender', function(evt) {
		// Get the transform resolution from the layer frameState
		const resolution = evt.frameState.viewState.resolution;

		//clusterSource.setSource(resolution < 100 ? source : altSource);
		//source.refresh();
		//clusterSource.refresh();

		// Refresh if we change the source options
		if (options.alt &&
			options.alt.minResolution > resolution ^
			options.alt.minResolution > previousResolution)
			source.refresh();

		// Tune the clustering distance depending on the transform resolution
		if (previousResolution != resolution && // Only when changed
			typeof clusterSource.setDistance == 'function')
			clusterSource.setDistance(Math.max(8, Math.min(60, resolution)));

		previousResolution = resolution;
	});

	// Define the style of the cluster point & the groupped features
	function style(feature) {
		const features = feature.get('features') || [feature],
			icon = features[0].get('icon'),
			label = features[0].get('label'),
			area = ol.extent.getArea(
				features[0].getGeometry().getExtent()
			),
			styleOptions = typeof baseOptions.styleOptions == 'function' ?
			options.styleOptions(feature) :
			options.styleOptions || {};

		//HACK Memorize the options in the feature for hover display
		feature.hoverStyleOptions = hoverStyleOptions;

		// Clusters
		if (features.length > 1 ||
			parseInt(features[0].get('cluster'))) {
			let clusters = 0;
			for (let f in features)
				clusters += parseInt(features[f].get('cluster')) || 1;

			clusterStyleOptions.text.setText(clusters.toString());
			feature.set('hover', clusters.toString() + ' éléments');

			return new ol.style.Style(clusterStyleOptions);
		}

		// Single feature (point, line or poly)
		// Add a permanent label
		if (!baseOptions.clusterDistance || // If no clusterisation 
			(feature.get('features') // If not cluster marker 
			) && label) {
			labelStyleOptions.text = label;
			styleOptions.text = new ol.style.Text(labelStyleOptions);
		}

		// Include the feature in the cluster source (lines, polygons) to make it visible
		if (area) {
			const featureExists = clusterSource.forEachFeature(function(f) {
				if (features[0].ol_uid == f.ol_uid)
					return true;
			});

			if (!featureExists)
				clusterSource.addFeature(features[0]);
		} else
			// Add icon if one is defined in the properties
			if (icon)
				styleOptions.image = new ol.style.Icon({
					src: icon,
				});

		return new ol.style.Style(styleOptions);
	}

	return layer;
}

/**
 * Control to display labels on hovering & click
 * on features of vector layers having the following properties :
 * name : name of the feature
 * hover : full label on hover
 * link : go to a new URL when we click on the feature
 */
function controlHover() {
	const control = new ol.control.Control({
			element: document.createElement('div'), //HACK No button
		}),
		// Internal layer to temporary display hovered feature
		hoverLayer = new ol.layer.Vector({
			source: new ol.source.Vector(),
			zIndex: 1, // Above the features
			style: style,
		});

	function style(feature) {
		//BEST options label on hover point /ligne / surface ???
		const features = feature.get('features') || [feature],
			titles = [];

		if (feature.get('hover'))
			// Big clusters
			titles.push(feature.get('hover'));
		else
		if (features.length > 1)
			// Clusters
			for (let f in features)
				titles.push(features[f].get('name'));
		else
			// Point
			titles.push(features[0].get('hover'));

		feature.hoverStyleOptions.text.setText(titles.join('\n'));
		return new ol.style.Style(feature.hoverStyleOptions);
	}

	control.setMap = function(map) { //HACK execute actions on Map init
		ol.control.Control.prototype.setMap.call(this, map);
		map.addLayer(hoverLayer);

		let previousHoveredFeature;
		map.on(['pointermove', 'click'], function(evt) {
			// Get hovered feature
			const feature = map.forEachFeatureAtPixel(
				map.getEventPixel(evt.originalEvent),
				function(feature) {
					return feature;
				});

			if (feature) {
				const features = feature.get('features'),
					center = feature.getGeometry().getCoordinates(),
					link = (features ? features[0] : feature).get('link');

				if (evt.type == 'click') {
					// Single feature
					if (link) {
						if (evt.originalEvent.ctrlKey)
							window.open(link, '_blank').focus();
						else if (evt.originalEvent.shiftKey)
							// To specify feature open a new window
							window.open(link, '_blank', 'resizable=yes').focus();
						else
							window.location = link;
					}

					// Cluster
					else
						map.getView().animate({
							zoom: map.getView().getZoom() + 1,
							center: center,
						});
				}
			}

			// Make the hovered feature visible in a dedicated layer
			if (feature !== previousHoveredFeature) {
				if (previousHoveredFeature)
					hoverLayer.getSource().removeFeature(previousHoveredFeature);

				if (feature)
					hoverLayer.getSource().addFeature(feature);

				map.getViewport().style.cursor = feature ? 'pointer' : 'default';
				previousHoveredFeature = feature;
			}
		});
	};

	return control;
}