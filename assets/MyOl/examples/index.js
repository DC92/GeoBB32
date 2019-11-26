console.log(document.cookie);

/**
 * www.refuges.info areas layer
 * Requires layerVectorURL
 */
const layerMassifsWri = layerVectorURL({
		baseUrl: '//www.refuges.info/api/polygones?type_polygon=1',
		selectorName: 'wri-massifs',
		styleOptions: function(properties) {
			// Translates the color in RGBA to be transparent
			var cs = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(properties.couleur);
			return {
				fill: new ol.style.Fill({
					color: 'rgba(' +
						parseInt(cs[1], 16) + ',' +
						parseInt(cs[2], 16) + ',' +
						parseInt(cs[3], 16) +
						',0.5)',
				}),
				stroke: new ol.style.Stroke({
					color: 'black',
				})
			};
		},
		hoverStyleOptions: function(properties) {
			return {
				fill: new ol.style.Fill({
					color: properties.couleur,
				}),
				stroke: new ol.style.Stroke({
					color: 'black',
				}),
			};
		},
		label: function(properties) {
			return '<a href="' + properties.lien + '">' + properties.nom + '<a>';
		},
		href: function(properties) {
			return properties.lien;
		},
	}),

	/**
	 * Map
	 */
	map_ = new ol.Map({
		target: 'map',
		layers: [
			layerRefugesInfo({
				selectorName: 'wri-poi',
			}),
			layerChemineur({
				selectorName: 'chemineur',
			}),
			layerPyreneesRefuges({
				selectorName: 'prc',
			}),
			layerOverpass(),
			layerMassifsWri,
			layerMarker({
				imageUrl: 'cadre.png',
				idDisplay: 'marqueur',
				decimalSeparator: ',',
			}),
			layerMarker({
				imageUrl: 'viseur.png',
				idDisplay: 'viseur',
				decimalSeparator: ',',
				draggable: true,
			}),
		],
		controls: controlsCollection({
			geoKeys: {
				// Get your own (free) IGN key at http://professionnels.ign.fr/ign/contrats
				ign: 'hcxdz5f1p9emo4i1lch6ennl',
				// Get your own (free) THUNDERFOREST key at https://manage.thunderforest.com
				thunderforest: 'ee751f43b3af4614b01d1bce72785369',
				// Get your own (free) BING key at https://www.microsoft.com/en-us/maps/create-a-bing-maps-key
				bing: 'ArLngay7TxiroomF7HLEXCS7kTWexf1_1s1qiF7nbTYs2IkD3XLcUnvSlKbGRZxt'
				// SwissTopo : You need to register your domain in
				// https://shop.swisstopo.admin.ch/fr/products/geoservice/swisstopo_geoservices/WMTS_info
			},
		}),
	});

/**
 * Editor
 */
map_.addControl(controlEdit({
	geoJsonId: 'geojson',
	snapLayers: [layerMassifsWri],
	title: 'Modification d‘une ligne, d‘un polygone:\n' +
		'Activer ce bouton (couleur jaune) puis\n' +
		'Cliquer et déplacer un sommet pour modifier une ligne ou un polygone\n' +
		'Cliquer sur un segment puis déplacer pour créer un sommet\n' +
		'Alt+cliquer sur un sommet pour le supprimer\n' +
		'Alt+cliquer sur un segment à supprimer dans une ligne pour la couper\n' +
		'Alt+cliquer sur un segment à supprimer d‘un polygone pour le transformer en ligne\n' +
		'Joindre les extrémités deux lignes pour les fusionner\n' +
		'Joindre les extrémités d‘une ligne pour la transformer en polygone\n' +
		'Ctrl+Alt+cliquer sur un côté d‘une ligne ou d‘un polygone pour les supprimer',
	editLine: 'Création d‘une ligne:\n' +
		'Activer ce bouton (couleur jaune) puis\n' +
		'Cliquer sur la carte et sur chaque point désiré pour dessiner une ligne,\n' +
		'double cliquer pour terminer.\n' +
		'Cliquer sur une extrémité d‘une ligne pour l‘étendre',
	editPolygon: 'Création d‘un polygone:\n' +
		'Activer ce bouton (couleur jaune) puis\n' +
		'Cliquer sur la carte et sur chaque point désiré pour dessiner un polygone,\n' +
		'double cliquer pour terminer.\n' +
		'Si le nouveau polygone est entièrement compris dans un autre, il crée un "trou".',
	saveFeatures: function(coordinates, format) {
		return format.writeGeometry(
			new ol.geom.MultiPolygon(coordinates.polys), {
				featureProjection: 'EPSG:3857',
				decimals: 5,
			});
	},
}));