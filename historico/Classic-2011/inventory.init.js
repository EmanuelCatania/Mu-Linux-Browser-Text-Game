$(document).ready( function() {
var target_id = '.item';
drag_init(target_id);
drop_init();


$('.item').rightClick( function(){
  t_id = $(this).parent().attr('id');
  if(t_id<64)
  {
   var item_type = js_current_inventory[t_id].slice(0,1);
   var item_sub_type = js_current_inventory[t_id].slice(5,6);
   var item_name = js_current_inventory[t_id].slice(57);

        newVal = '';
        item_name = item_name.split('_');
        for(var c=0; c < item_name.length; c++) {
                newVal += item_name[c].substring(0,1).toUpperCase() +
item_name[c].substring(1, item_name[c].length) + ' ';
        }
        item_name = newVal;
  
   if(item_type == 'F' || item_type == 'G' || item_type == 'J' || (item_type == 'K' && item_sub_type == '5'))
   jConfirm('Do you want to use ' + item_name + '?', 'Confirm Using', function(r) {using_confirmed(r, t_id, item_type, item_sub_type);});
  }
});


});