<?PHP

	// configuration variables
		// instagram client ID --- UNIQUE TO MY ACCOUNT; DELETE BEFORE DEPLOYMENT
	$instagramClientID = 'cd196527a979472c94f6977d42696387';
		// cartodb api key --- UNIQUE TO MY ACCOUNT; DELETE BEFORE DEPLOYMENT
	$cartoDBAPIKey = 'ee7a6ed02cc520814fb72e69297df9f426f25ea8';
		// base tag to flag instagram as a report
	$baseTag = 'trawl123';
		// tag prefix to indidcate ship name/ID
	$vesselTagPrefix = 'trawl';

?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Instagram Test</title>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>
<script language="javascript" type="text/javascript">

	// records to populate
	var records = [];
	// api ids used to check for dups
	var apiids = [];
	// columns in the DB
	var columns = ["iuu_eventreport_username","iuu_eventlat","iuu_eventlng","iuu_eventreporttag","iuu_eventvessel","iuu_eventcaption","iuu_eventthumbnailurl","iuu_eventimageurl","iuu_eventtime","the_geom","iuu_apiid"];
	
	$(function () {
		// instagram redirects to the redirect_url + #access_token so, if that hash is present, then the API can be called
		if (!window.location.hash || (window.location.hash && window.location.hash.toLowerCase().indexOf('access_token=') == -1))
			window.location.href = 'https://instagram.com/oauth/authorize/?client_id=<?= $instagramClientID ?>&redirect_uri=http://shuaige.org/projects/ghanafish/mine.php&response_type=token';
		
		// pull access token from hash string
		var accessToken = window.location.hash.substr(window.location.hash.indexOf('access_token=') + 13);
		
		// make instagram ajax call to pull data
		$.ajax('https://api.instagram.com/v1/tags/trawl123/media/recent?access_token=' + accessToken, {
			dataType:"jsonp", complete:function (hr, status) {
				// if not successful, output an error message (could be expanded upon for more graceful error handling)
				if (status == 'success') return true;
				alert('An error has occurred. Status: ' + status);
			}, success:function (data) {
				// no results from instagram
				if (!data.data.length) {
					alert('No results from Instagram.');
					return false;
				}
				// loop through the instagram results
				for (var i = 0; i < data.data.length; i++) {
					// not an image (could be a video)
					if (data.data[i].type != 'image') continue;
					// no location data (just ignore for now, but could be entered into the DB and reviewed by staff)
					if (!data.data[i].location) continue;
					if (typeof(data.data[i].location.latitude) == 'undefined') continue;
					
					// store api id
					apiids.push(data.data[i].id);
					
					// assemble base record object
					var record = {
						// unique id from api not in the current records
						"iuu_apiid":data.data[i].id,
						// username of user who took the photo
						"iuu_eventreport_username":data.data[i].user.username,
						// latitude of the photo
						"iuu_eventlat":data.data[i].location.latitude,
						// longitude of the photo
						"iuu_eventlng":data.data[i].location.longitude,
						// all tags in the photo
						"iuu_eventreporttag":data.data[i].tags.join(', '),
						// initialize vessel name/ID
						"iuu_eventvessel":null,
						// full caption of photo (includes tags)
						"iuu_eventcaption":data.data[i].caption.text,
						// URL for thumbnail of photo
						"iuu_eventthumbnailurl":data.data[i].images.thumbnail.url,
						// URL for large version of photo
						"iuu_eventimageurl":data.data[i].images.standard_resolution.url,
						// for cartoDB
						"the_geom":"ST_SetSRID(ST_Point(" + data.data[i].location.longitude + ', ' + data.data[i].location.latitude + "),4326)"
					};
					
					// time
					var dt = new Date(parseInt(data.data[i].created_time) * 1000);
					record.iuu_eventtime = (dt.getMonth() + 1) + "/" + dt.getDate() + "/" + dt.getFullYear() + " " + dt.getHours() + ":" + dt.getMinutes() + ":" + dt.getSeconds();
					
					// vessel
					for (var v = 0; v < data.data[i].tags.length; v++) {
						if (data.data[i].tags[v].substr(0, <?= strlen($vesselTagPrefix) ?>) != "<?= $vesselTagPrefix ?>") continue;
						if (data.data[i].tags[v] == "<?= $baseTag ?>") continue;
						
						record.iuu_eventvessel = data.data[i].tags[v].substr(<?= strlen($vesselTagPrefix) ?>);
					}
					
					// add current record to collection
					records.push(record);
				}
				
				// insert record collection
				insertRecords();
			}
		});
		
	});
	
	function insertRecords () {
		// dups check
		$.ajax("http://mattm.cartodb.com/api/v2/sql?q=SELECT iuu_apiid AS id FROM ghana_iuu_data_test WHERE iuu_apiid IN ('" + apiids.join("','") + "')&api_key=<?= $cartoDBAPIKey ?>",{
			dataType:'jsonp',
			complete:function (hr, status) {
				if (status == 'success') return true;
				alert('An error occurred in inserting the record. Status: ' + status);
			},
			success:function (data) {
				var dups = {};
				// loop through recordset to get dups
				for (var i = 0; i < data.rows.length; i++) {
					dups['a' + data.rows[i].id] = true;
				}
				var count = 0;
				// assemble INSERT sql
				for (var i = 0; i < records.length; i++) {
					// check for dup, skip if it is
					if (typeof(dups['a' + records[i].iuu_apiid]) != 'undefined') continue;
					
					var sql = "INSERT INTO ghana_iuu_data_test (" + columns.join(',') + ") VALUES(";
					for (var c = 0; c < columns.length; c++) {
						if (c) sql += ',';
						if (records[i][columns[c]]) {
							if (columns[c] == 'the_geom' || typeof(records[i][columns[c]]) != 'string') sql += records[i][columns[c]];
							else sql += "'" + records[i][columns[c]].replace(/\'/g, "''") + "'"; 
						}
						else sql += 'NULL';
					}
					sql += ")";
					count++;
					
					// postgresql supports multiple inserts in a single query, however, since we put the SQL query string into an URL variable, we're constrained by the max URL length, which can be easily reached when dealing with several records. Thus, here, we insert one record at a time.
					$.ajax('http://mattm.cartodb.com/api/v2/sql?q=' + encodeURIComponent(sql) + '&api_key=<?= $cartoDBAPIKey ?>',{
						dataType:'jsonp',
						complete:function (hr, status) {
							if (status == 'success') return true;
							alert('An error occurred in inserting the record. Status: ' + status);
						},
						success:function (data) {
						}
					});
				}
				// notify user of number of records to be inserted
				alert(count + ' records uploaded.');
			}
		});
	}

</script>
</head>

<body>
</body>
</html>
