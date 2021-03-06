@extends("layouts.admin.default")
@section("title","编辑角色")
@section("content")
    <form class="form-horizontal" action="" method="post" enctype="multipart/form-data">
        {{ csrf_field() }}
        <div class="form-group">
            <label class="col-sm-2 control-label">角色名称</label>
            <div class="col-sm-10">
                <input type="text" name="name" value="{{$role->name}}" class="form-control">
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-2 control-label">权限名称</label>
            <div class="col-sm-10">
                @foreach($pers as $per)
                    <input type="checkbox" name="per[]" value="{{$per->name}}"
                           @if($role->hasPermissionTo($per->name)) checked @endif>{{$per->name}}
                @endforeach
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-offset-2 col-sm-10">
                <button type="submit" class="btn btn-success">提交</button>
            </div>
        </div>
    </form>
@endsection