# AOE Static

Changelog

* Renamed placeholder class from .placeholder to .as-placeholder (This is a breaking change!)


If xkey mode is used, following config in varnish is needed:

import xkey;

sub vcl_recv {
...
if (req.method == "BAN") {
            if(req.http.X-Tags) {
                ban("obj.http.X-Tags ~ " + req.http.X-Tags);
            }
            if(req.http.X-Url) {
                ban("obj.http.X-Url ~ " + req.http.X-Url);
            }
           if (req.http.xkey-purge) {
               set req.http.tags = req.http.xkey-purge;
               set req.http.n-gone = xkey.softpurge(req.http.xkey-purge);
           }

          return (synth(200, "Invalidated "+req.http.n-gone+" objects" + " tried: " +req.http.xkey-purge));
        }

...
}

sub vcl_hit {
    if (obj.ttl >= 0s) {
        // A pure unadultered hit, deliver it
       set req.http.X-GraceType = "Fresh Hit";
        set req.http.X-grace = obj.grace;
        set req.http.X-ttl = obj.ttl;
        return (deliver);
    }
    if (obj.ttl + obj.grace > 0s) {
       // Object is in grace, deliver it
        // Automatically triggers a background fetch
        set req.http.X-GraceType = "Stale Hit";
        set req.http.X-grace = obj.grace;
        set req.http.X-ttl = obj.ttl;

        return (deliver);
    }
    // fetch & deliver once we get the result
    set req.http.X-GraceType = "Fetched from Backend";

    return (fetch);
}
sub vcl_backend_response {
...
    if (beresp.http.X-Aoestatic == "cache") {
         ...
        set beresp.http.xkey = beresp.http.xkey-purge;
    }
}


sub vcl_deliver {
...
if (beresp.http.X-Aoestatic == "cache") {
    set resp.http.X-GraceType = req.http.X-GraceType;
    set resp.http.X-grace = req.http.X-grace;
    set resp.http.X-ttl = req.http.X-ttl;
    set resp.http.xkey = req.http.xkey;
    ...
}
...
}